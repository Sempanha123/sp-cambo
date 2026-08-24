<?php

namespace App\Services;

use App\Exceptions\FulfillmentClaimException;
use App\Models\ApiKey;
use App\Models\FulfillmentClaim;
use App\Models\ModelAlias;
use App\Models\OrderItem;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentClaimService
{
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

        $claim = DB::transaction(function () use ($tenant, $item, $idempotencyKey): FulfillmentClaim {
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
                'claim_snapshot' => $this->snapshot($item),
                'expires_at' => now()->addDays(7),
                'status' => 'PENDING',
                'source_idempotency_key' => $idempotencyKey,
            ]);

            $item->forceFill(['fulfillment_claim_id' => $claim->id])->save();

            $this->audit->record(
                $tenant->user,
                'fulfillment_claim.created',
                'fulfillment_claim',
                $claim->id,
                'Created fulfillment claim.',
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
     * @return array{claim: FulfillmentClaim, secret: string|null, key: ApiKey, reused: bool}
     */
    public function claim(
        Tenant $tenant,
        FulfillmentClaim $claim,
        string $idempotencyKey,
        ?ApiKey $existingKey = null
    ): array {
        if ((string) $claim->tenant_id !== (string) $tenant->id) {
            throw new FulfillmentClaimException(
                'This fulfillment claim does not belong to your account.',
                'claim_not_found',
                404
            );
        }

        if ($claim->status !== 'PENDING') {
            throw new FulfillmentClaimException(
                'This claim cannot be fulfilled.',
                'claim_unfulfillable'
            );
        }

        if ($claim->expires_at->isPast()) {
            $claim->update(['status' => 'EXPIRED']);

            throw new FulfillmentClaimException(
                'This claim has expired.',
                'claim_expired'
            );
        }

        return DB::transaction(function () use ($tenant, $claim, $idempotencyKey, $existingKey): array {
            $claim = FulfillmentClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->status !== 'PENDING') {
                throw new FulfillmentClaimException(
                    'This claim cannot be fulfilled.',
                    'claim_unfulfillable'
                );
            }

            $user = $tenant->user;
            if (! $user) {
                throw new FulfillmentClaimException(
                    'The workspace owner could not be resolved.',
                    'claim_unfulfillable'
                );
            }

            $aliasIds = ModelAlias::query()
                ->whereIn('public_alias', $claim->claim_snapshot['allowed_model_aliases'] ?? [])
                ->pluck('id')
                ->all();

            if ($aliasIds === []) {
                throw ValidationException::withMessages([
                    'models' => ['No purchased model aliases are currently resolvable.'],
                ]);
            }

            if ($existingKey !== null) {
                $existingKey = ApiKey::query()
                    ->with('modelAliases')
                    ->lockForUpdate()
                    ->findOrFail($existingKey->id);

                if ((int) $existingKey->user_id !== (int) $user->id
                    || $existingKey->status !== 'ACTIVE'
                    || $existingKey->revoked_at !== null) {
                    throw ValidationException::withMessages([
                        'existing_api_key_id' => ['Choose one of your active SP Cambo API keys.'],
                    ]);
                }

                if ($existingKey->expires_at?->isPast()) {
                    throw ValidationException::withMessages([
                        'existing_api_key_id' => ['That API key has expired.'],
                    ]);
                }

                $existingKey->modelAliases()->syncWithoutDetaching($aliasIds);

                $claimExpiry = filled($claim->claim_snapshot['expires_at'] ?? null)
                    ? Carbon::parse($claim->claim_snapshot['expires_at'])
                    : null;

                if ($existingKey->expires_at !== null
                    && $claimExpiry !== null
                    && $claimExpiry->greaterThan($existingKey->expires_at)) {
                    $existingKey->forceFill(['expires_at' => $claimExpiry])->save();
                }

                $key = $existingKey->fresh('modelAliases');
                $secret = null;
                $reused = true;
            } else {
                $created = $this->secrets->create(
                    $user,
                    [
                        'label' => "Auto-created for order item {$claim->order_item_id}",
                        'expires_at' => filled($claim->claim_snapshot['expires_at'] ?? null)
                            ? Carbon::parse($claim->claim_snapshot['expires_at'])
                            : null,
                    ],
                    $aliasIds
                );

                $key = $created['key'];
                $secret = $created['secret'];
                $reused = false;
            }

            $claim->update([
                'status' => 'CLAIMED',
                'claimed_at' => now(),
                'api_key_id' => $key->id,
                'source_idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->record(
                $user,
                'fulfillment_claim.claimed',
                'fulfillment_claim',
                $claim->id,
                $reused
                    ? 'Added purchased access to an existing API key.'
                    : 'Created an API key for purchased access.',
                [
                    'order_id' => $claim->order_id,
                    'order_item_id' => $claim->order_item_id,
                    'api_key_id' => $key->id,
                    'reused' => $reused,
                ]
            );

            return [
                'claim' => $claim,
                'secret' => $secret,
                'key' => $key,
                'reused' => $reused,
            ];
        });
    }

    private function snapshot(OrderItem $item): array
    {
        $snapshot = $item->package_snapshot;

        if (! is_array($snapshot)) {
            throw ValidationException::withMessages([
                'package' => ['The immutable package snapshot is unavailable for this order item.'],
            ]);
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
