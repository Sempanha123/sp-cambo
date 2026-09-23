<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResellerStockService
{
    public const SOURCE_TYPE = 'RESELLER_STOCK';
    public const ACCESS_SCOPE = 'RESELLER';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param array<int,string> $publicAliases
     * @return array{lot: EntitlementLot, replayed: bool}
     */
    public function grant(
        ?User $actor,
        User $reseller,
        string $billingMode,
        int $units,
        array $publicAliases,
        string $reason,
        string $idempotencyKey,
        ?Carbon $expiresAt = null,
    ): array {
        $billingMode = strtoupper(trim($billingMode));
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);
        $aliases = collect($publicAliases)
            ->filter(fn ($alias): bool => is_string($alias) && trim($alias) !== '')
            ->map(fn (string $alias): string => trim($alias))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (! in_array($billingMode, ['TOKEN_QUOTA', 'CREDIT_BALANCE'], true)) {
            throw ValidationException::withMessages(['billing_mode' => ['Choose TOKEN_QUOTA or CREDIT_BALANCE.']]);
        }
        if ($units < 1) {
            throw ValidationException::withMessages(['units' => ['Grant at least one unit.']]);
        }
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => ['Write at least 10 characters for the audit trail.']]);
        }
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 150) {
            throw ValidationException::withMessages(['idempotency_key' => ['Use a stable idempotency key of 1 to 150 characters.']]);
        }
        if ($aliases === []) {
            throw ValidationException::withMessages(['public_model_aliases' => ['Choose at least one published model alias.']]);
        }
        if (! $reseller->hasPermission('reseller.manage')) {
            throw ValidationException::withMessages(['reseller' => ['That account does not have reseller.manage.']]);
        }
        if (! DB::table('reseller_profiles')->where('user_id', $reseller->id)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['reseller' => ['The reseller profile must be ACTIVE before stock can be granted.']]);
        }

        $published = ModelAlias::query()
            ->published()
            ->with('pricing')
            ->whereIn('public_alias', $aliases)
            ->get();
        $publishedAliases = $published->pluck('public_alias')->sort()->values()->all();
        if ($publishedAliases !== $aliases) {
            throw ValidationException::withMessages(['public_model_aliases' => ['Every selected model alias must currently be published.']]);
        }

        $currency = null;
        $currencyExponent = null;
        $unitLabel = 'tokens';
        if ($billingMode === 'CREDIT_BALANCE') {
            $pricing = $published->pluck('pricing');
            if ($pricing->contains(null)) {
                throw ValidationException::withMessages(['public_model_aliases' => ['Every selected model needs credit pricing before CREDIT_BALANCE stock can be granted.']]);
            }
            $currencies = $pricing->pluck('currency')->filter()->unique()->values();
            $exponents = $pricing->pluck('exponent')->filter(fn ($value) => $value !== null)->map(fn ($value): int => (int) $value)->unique()->values();
            if ($currencies->count() !== 1 || $exponents->count() !== 1) {
                throw ValidationException::withMessages(['public_model_aliases' => ['Selected credit-priced models must use one currency and one exponent.']]);
            }
            $currency = (string) $currencies->first();
            $currencyExponent = (int) $exponents->first();
            $unitLabel = $currency.' minor units';
        }

        $ledgerKey = 'reseller-stock:'.$idempotencyKey;
        $existingLedger = CreditLedger::query()->where('idempotency_key', $ledgerKey)->first();
        if ($existingLedger !== null) {
            $existing = EntitlementLot::query()->findOrFail($existingLedger->entitlement_lot_id);
            $sameAliases = collect($existing->allowed_model_aliases ?? [])->sort()->values()->all() === $aliases;
            $sameExpiry = $existing->expires_at?->getTimestamp() === $expiresAt?->getTimestamp();
            if ((int) $existing->user_id !== (int) $reseller->id
                || $existing->source_type !== self::SOURCE_TYPE
                || ($existing->access_scope ?? null) !== self::ACCESS_SCOPE
                || $existing->billing_mode !== $billingMode
                || (int) $existing->original_units !== $units
                || ! $sameAliases
                || ! $sameExpiry) {
                throw new InferenceIdempotencyException('The reseller-stock idempotency key was already used for different inputs.');
            }

            return ['lot' => $existing, 'replayed' => true];
        }

        $lot = $this->entitlements->grant($reseller, [
            'source_type' => self::SOURCE_TYPE,
            'source_id' => 'stock:'.substr(hash('sha256', $idempotencyKey), 0, 32),
            'package_name' => 'Reseller stock',
            'family_label' => 'Reseller inventory',
            'billing_mode' => $billingMode,
            'original_units' => $units,
            'unit_label' => $unitLabel,
            'currency' => $currency,
            'currency_exponent' => $currencyExponent,
            'allowed_model_aliases' => $aliases,
            'billing_snapshot' => [
                'billing_rules' => [
                    'stock_kind' => self::SOURCE_TYPE,
                ],
            ],
            'activated_at' => now(),
            'expires_at' => $expiresAt,
            'access_scope' => self::ACCESS_SCOPE,
            'bound_api_key_id' => null,
            'fulfillment_claim_id' => null,
            'reason' => $reason,
        ], $ledgerKey);

        $metadata = [
            'reseller_user_id' => $reseller->id,
            'billing_mode' => $billingMode,
            'units' => (string) $units,
            'public_model_aliases' => $aliases,
            'expires_at' => $expiresAt?->toAtomString(),
        ];
        if ($actor !== null) {
            $this->audit->record($actor, 'reseller_stock.granted', 'entitlement_lot', $lot->id, $reason, $metadata);
        } else {
            $this->audit->recordSystem('reseller_stock.granted', 'entitlement_lot', $lot->id, $reason, $metadata);
        }

        return ['lot' => $lot, 'replayed' => false];
    }
}
