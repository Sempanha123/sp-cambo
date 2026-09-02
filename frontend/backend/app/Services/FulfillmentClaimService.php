<?php

namespace App\Services;

use App\Exceptions\FulfillmentClaimException;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\FulfillmentClaim;
use App\Models\ModelAlias;
use App\Models\OrderItem;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentClaimService
{
    public const MODE_PLAYGROUND = 'PLAYGROUND';
    public const MODE_NEW = 'NEW';
    public const MODE_EXISTING = 'EXISTING';

    public function __construct(
        private readonly ApiKeySecretService $secrets,
        private readonly AuditService $audit,
    ) {}

    /** @return array{claim: FulfillmentClaim, secret: null} */
    public function create(Tenant $tenant, OrderItem $item, string $idempotencyKey): array
    {
        if ($item->fulfillment_claim_id !== null) {
            $existing = FulfillmentClaim::query()->find($item->fulfillment_claim_id);
            if ($existing) {
                return ['claim' => $existing, 'secret' => null];
            }
        }

        $snapshot = $this->snapshot($item);
        $packageExpiry = filled($snapshot['expires_at'] ?? null) ? Carbon::parse($snapshot['expires_at']) : null;
        $claimExpiry = now()->addDays(7);
        if ($packageExpiry !== null && $packageExpiry->lessThan($claimExpiry)) {
            $claimExpiry = $packageExpiry;
        }

        $claim = DB::transaction(function () use ($tenant, $item, $idempotencyKey, $snapshot, $claimExpiry): FulfillmentClaim {
            $existing = FulfillmentClaim::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $claim = FulfillmentClaim::query()->create([
                'tenant_id' => $tenant->id,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'claim_snapshot' => $snapshot,
                'expires_at' => $claimExpiry,
                'status' => 'PENDING',
                'delivery_mode' => null,
                'source_idempotency_key' => $idempotencyKey,
            ]);

            $item->forceFill(['fulfillment_claim_id' => $claim->id])->save();

            $this->audit->record(
                $tenant->user,
                'fulfillment_claim.created',
                'fulfillment_claim',
                $claim->id,
                'Created purchased-access allocation claim.',
                [
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'package_slug' => $item->package_slug,
                    'quantity' => $item->quantity,
                ]
            );

            return $claim;
        });

        return ['claim' => $claim, 'secret' => null];
    }

    /**
     * Allocate a purchased claim to exactly one access target.
     *
     * PLAYGROUND keeps the purchased lot away from normal API keys.
     * NEW creates a dedicated key and binds the purchased lot to it.
     * EXISTING binds the purchased lot to one customer-owned key.
     *
     * @return array{claim: FulfillmentClaim, secret: string|null, key: ApiKey|null, reused: bool, delivery_mode: string}
     */
    public function claim(
        Tenant $tenant,
        FulfillmentClaim $claim,
        string $idempotencyKey,
        ?ApiKey $existingKey = null,
        string $mode = self::MODE_NEW,
    ): array {
        $mode = strtoupper(trim($mode));
        if (! in_array($mode, [self::MODE_PLAYGROUND, self::MODE_NEW, self::MODE_EXISTING], true)) {
            throw ValidationException::withMessages(['mode' => ['Choose Playground, Create a new API key, or Use an existing API key.']]);
        }
        if ($mode === self::MODE_EXISTING && $existingKey === null) {
            throw ValidationException::withMessages(['existing_api_key_id' => ['Choose one of your active SP Cambo API keys.']]);
        }

        if ((string) $claim->tenant_id !== (string) $tenant->id) {
            throw new FulfillmentClaimException('This fulfillment claim does not belong to your account.', 'claim_not_found', 404);
        }
        if ($claim->status !== 'PENDING') {
            throw new FulfillmentClaimException('This claim cannot be fulfilled.', 'claim_unfulfillable');
        }
        if ($claim->expires_at->isPast()) {
            $claim->update(['status' => 'EXPIRED']);
            throw new FulfillmentClaimException('This claim has expired.', 'claim_expired');
        }

        return DB::transaction(function () use ($tenant, $claim, $idempotencyKey, $existingKey, $mode): array {
            $claim = FulfillmentClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($claim->status !== 'PENDING') {
                throw new FulfillmentClaimException('This claim cannot be fulfilled.', 'claim_unfulfillable');
            }

            $user = $tenant->user;
            if (! $user) {
                throw new FulfillmentClaimException('The workspace owner could not be resolved.', 'claim_unfulfillable');
            }

            $key = null;
            $secret = null;
            $reused = false;

            if ($mode !== self::MODE_PLAYGROUND) {
                $aliasIds = ModelAlias::query()
                    ->published()
                    ->whereIn('public_alias', $claim->claim_snapshot['allowed_model_aliases'] ?? [])
                    ->pluck('id')
                    ->all();

                if ($aliasIds === []) {
                    throw ValidationException::withMessages(['models' => ['No purchased model aliases are currently publishable for API access.']]);
                }

                if ($mode === self::MODE_EXISTING) {
                    $existingKey = ApiKey::query()->with('modelAliases')->lockForUpdate()->findOrFail($existingKey?->id);
                    if ((int) $existingKey->user_id !== (int) $user->id
                        || $existingKey->status !== 'ACTIVE'
                        || $existingKey->revoked_at !== null) {
                        throw ValidationException::withMessages(['existing_api_key_id' => ['Choose one of your active SP Cambo API keys.']]);
                    }
                    if ($existingKey->expires_at?->isPast()) {
                        throw ValidationException::withMessages(['existing_api_key_id' => ['That API key has expired.']]);
                    }
                    $existingKey->modelAliases()->syncWithoutDetaching($aliasIds);
                    $claimExpiry = filled($claim->claim_snapshot['expires_at'] ?? null)
                        ? Carbon::parse($claim->claim_snapshot['expires_at'])
                        : null;
                    if ($existingKey->expires_at !== null && $claimExpiry !== null && $claimExpiry->greaterThan($existingKey->expires_at)) {
                        $existingKey->forceFill(['expires_at' => $claimExpiry])->save();
                    }
                    $key = $existingKey->fresh('modelAliases');
                    $reused = true;
                } else {
                    $label = trim((string) ($claim->claim_snapshot['package_name'] ?? 'Purchased access'));
                    $created = $this->secrets->create(
                        $user,
                        [
                            'label' => mb_substr('Purchased · '.$label, 0, 100),
                            'expires_at' => filled($claim->claim_snapshot['expires_at'] ?? null)
                                ? Carbon::parse($claim->claim_snapshot['expires_at'])
                                : null,
                        ],
                        $aliasIds
                    );
                    $key = $created['key'];
                    $secret = $created['secret'];
                }
            }

            $lots = EntitlementLot::query()
                ->where('fulfillment_claim_id', $claim->id)
                ->lockForUpdate()
                ->get();

            if ($lots->isNotEmpty()) {
                if ($mode === self::MODE_PLAYGROUND) {
                    EntitlementLot::query()->whereIn('id', $lots->pluck('id'))->update([
                        'access_scope' => 'PLAYGROUND',
                        'bound_api_key_id' => null,
                    ]);
                } else {
                    EntitlementLot::query()->whereIn('id', $lots->pluck('id'))->update([
                        'access_scope' => 'API_KEY',
                        'bound_api_key_id' => $key?->id,
                    ]);
                }
            }

            $claim->update([
                'status' => 'CLAIMED',
                'claimed_at' => now(),
                'api_key_id' => $key?->id,
                'delivery_mode' => $mode,
                'source_idempotency_key' => $idempotencyKey,
            ]);

            $message = match ($mode) {
                self::MODE_PLAYGROUND => 'Allocated purchased access to Playground balance.',
                self::MODE_EXISTING => 'Allocated purchased access to an existing API key.',
                default => 'Created a dedicated API key for purchased access.',
            };
            $this->audit->record(
                $user,
                'fulfillment_claim.claimed',
                'fulfillment_claim',
                $claim->id,
                $message,
                [
                    'order_id' => $claim->order_id,
                    'order_item_id' => $claim->order_item_id,
                    'api_key_id' => $key?->id,
                    'delivery_mode' => $mode,
                    'reused' => $reused,
                ]
            );

            return [
                'claim' => $claim->fresh(),
                'secret' => $secret,
                'key' => $key,
                'reused' => $reused,
                'delivery_mode' => $mode,
            ];
        });
    }

    private function snapshot(OrderItem $item): array
    {
        $snapshot = $item->package_snapshot;
        if (! is_array($snapshot)) {
            throw ValidationException::withMessages(['package' => ['The immutable package snapshot is unavailable for this order item.']]);
        }

        return [
            'package_slug' => $item->package_slug,
            'package_name' => $item->package_name,
            'quantity' => $item->quantity,
            'auto_creates_api_key' => (bool) ($snapshot['auto_creates_api_key'] ?? false),
            'allowed_model_aliases' => array_values(array_unique($snapshot['allowed_model_aliases'] ?? [])),
            'expires_at' => ! empty($snapshot['duration_seconds'])
                ? now()->addSeconds((int) $snapshot['duration_seconds'])->toAtomString()
                : null,
        ];
    }
}
