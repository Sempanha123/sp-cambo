<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\ResellerCustomer;
use App\Models\ResellerTransfer;
use App\Models\ResellerTransferAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResellerAllocationService
{
    /**
     * Existing database schema requires reseller_transfers.public_model_alias.
     * Package-level allocations use this internal sentinel while the actual
     * customer entitlement receives the complete model list from the source lot.
     */
    public const PACKAGE_SCOPE_SENTINEL = '__PACKAGE__';

    public function __construct(
        private readonly ResellerStockService $stocks,
    ) {}

    /**
     * Backward-compatible single-model allocation.
     */
    public function allocate(
        User $reseller,
        User $customer,
        string $billingMode,
        string $publicAlias,
        int $units,
        string $idempotencyKey,
        string $reason
    ): ResellerTransfer {
        $this->validateCommon($reseller, $units, $idempotencyKey, $reason);

        $this->stocks->adoptEligiblePurchases($reseller);

        return DB::transaction(function () use (
            $reseller,
            $customer,
            $billingMode,
            $publicAlias,
            $units,
            $idempotencyKey,
            $reason
        ): ResellerTransfer {
            $this->assertManagedCustomer($reseller, $customer);

            $existing = ResellerTransfer::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ((int) $existing->reseller_user_id !== (int) $reseller->id
                    || (int) $existing->customer_user_id !== (int) $customer->id
                    || $existing->billing_mode !== $billingMode
                    || $existing->public_model_alias !== $publicAlias
                    || (int) $existing->units !== $units) {
                    throw new InferenceIdempotencyException(
                        'The allocation idempotency key was already used for different inputs.'
                    );
                }

                return $existing->load('allocations');
            }

            $lots = EntitlementLot::query()
                ->where('user_id', $reseller->id)
                ->where('billing_mode', $billingMode)
                ->where('source_type', ResellerStockService::SOURCE_TYPE)
                ->where('access_scope', ResellerStockService::ACCESS_SCOPE)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->whereJsonContains('allowed_model_aliases', $publicAlias)
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $available = $lots->sum(
                fn (EntitlementLot $lot): int =>
                    max(0, (int) $lot->remaining_units - (int) $lot->reserved_units)
            );

            if ($available < $units) {
                throw new InsufficientBalanceException($billingMode);
            }

            $transfer = ResellerTransfer::query()->create([
                'reseller_user_id' => $reseller->id,
                'customer_user_id' => $customer->id,
                'billing_mode' => $billingMode,
                'public_model_alias' => $publicAlias,
                'units' => $units,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
            ]);

            $needed = $units;

            foreach ($lots as $source) {
                $take = min(
                    $needed,
                    max(0, (int) $source->remaining_units - (int) $source->reserved_units)
                );

                if ($take === 0) {
                    continue;
                }

                $target = $this->moveUnits(
                    $reseller,
                    $customer,
                    $transfer,
                    $source,
                    $take,
                    [$publicAlias],
                    $reason
                );

                ResellerTransferAllocation::query()->create([
                    'reseller_transfer_id' => $transfer->id,
                    'source_entitlement_lot_id' => $source->id,
                    'target_entitlement_lot_id' => $target->id,
                    'units' => $take,
                ]);

                $needed -= $take;

                if ($needed === 0) {
                    break;
                }
            }

            return $transfer->load('allocations');
        });
    }

    /**
     * Preferred package-level allocation.
     *
     * The seller chooses one reseller inventory lot, not one model.
     * The customer's new entitlement inherits ALL model aliases from that lot.
     *
     * Example:
     * Claude 10M lot -> sell 1M -> customer receives one shared 1M pool that can
     * be spent across every Claude alias included in that package.
     */
    public function allocatePackage(
        User $reseller,
        User $customer,
        string $inventoryLotId,
        int $units,
        string $idempotencyKey,
        string $reason
    ): ResellerTransfer {
        $this->validateCommon($reseller, $units, $idempotencyKey, $reason);

        $this->stocks->adoptEligiblePurchases($reseller);

        return DB::transaction(function () use (
            $reseller,
            $customer,
            $inventoryLotId,
            $units,
            $idempotencyKey,
            $reason
        ): ResellerTransfer {
            $this->assertManagedCustomer($reseller, $customer);

            $existing = ResellerTransfer::query()
                ->with('allocations')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $existingSourceId = (string) optional($existing->allocations->first())
                    ->source_entitlement_lot_id;

                if ((int) $existing->reseller_user_id !== (int) $reseller->id
                    || (int) $existing->customer_user_id !== (int) $customer->id
                    || $existing->public_model_alias !== self::PACKAGE_SCOPE_SENTINEL
                    || (int) $existing->units !== $units
                    || $existingSourceId !== $inventoryLotId) {
                    throw new InferenceIdempotencyException(
                        'The allocation idempotency key was already used for different inputs.'
                    );
                }

                return $existing;
            }

            /** @var EntitlementLot $source */
            $source = EntitlementLot::query()
                ->whereKey($inventoryLotId)
                ->where('user_id', $reseller->id)
                ->where('source_type', ResellerStockService::SOURCE_TYPE)
                ->where('access_scope', ResellerStockService::ACCESS_SCOPE)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->lockForUpdate()
                ->firstOrFail();

            $available = max(
                0,
                (int) $source->remaining_units - (int) $source->reserved_units
            );

            if ($available < $units) {
                throw new InsufficientBalanceException((string) $source->billing_mode);
            }

            $aliases = collect($source->allowed_model_aliases ?? [])
                ->filter(fn ($alias): bool => is_string($alias) && trim($alias) !== '')
                ->map(fn (string $alias): string => trim($alias))
                ->unique()
                ->values()
                ->all();

            if ($aliases === []) {
                throw ValidationException::withMessages([
                    'inventory_lot_id' => [
                        'That reseller inventory lot has no model scope and cannot be sold.',
                    ],
                ]);
            }

            $transfer = ResellerTransfer::query()->create([
                'reseller_user_id' => $reseller->id,
                'customer_user_id' => $customer->id,
                'billing_mode' => $source->billing_mode,
                'public_model_alias' => self::PACKAGE_SCOPE_SENTINEL,
                'units' => $units,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
            ]);

            $target = $this->moveUnits(
                $reseller,
                $customer,
                $transfer,
                $source,
                $units,
                $aliases,
                $reason
            );

            ResellerTransferAllocation::query()->create([
                'reseller_transfer_id' => $transfer->id,
                'source_entitlement_lot_id' => $source->id,
                'target_entitlement_lot_id' => $target->id,
                'units' => $units,
            ]);

            return $transfer->load('allocations');
        });
    }

    private function validateCommon(
        User $reseller,
        int $units,
        string $idempotencyKey,
        string $reason
    ): void {
        if ($units <= 0) {
            throw ValidationException::withMessages([
                'units' => ['Allocate at least one unit.'],
            ]);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['Write at least 10 characters for the audit trail.'],
            ]);
        }

        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Use a stable idempotency key of 1 to 191 characters.',
                ],
            ]);
        }

        if (! DB::table('reseller_profiles')
            ->where('user_id', $reseller->id)
            ->where('status', 'ACTIVE')
            ->exists()) {
            throw ValidationException::withMessages([
                'reseller' => ['Your reseller profile is not active.'],
            ]);
        }
    }

    private function assertManagedCustomer(User $reseller, User $customer): void
    {
        if (! ResellerCustomer::query()
            ->where('reseller_user_id', $reseller->id)
            ->where('customer_user_id', $customer->id)
            ->where('status', 'ACTIVE')
            ->exists()) {
            throw ValidationException::withMessages([
                'customer' => ['Customer is not actively managed by this reseller.'],
            ]);
        }
    }

    /**
     * Move one exact quantity out of one reseller stock lot into a customer lot.
     *
     * @param array<int,string> $targetAliases
     */
    private function moveUnits(
        User $reseller,
        User $customer,
        ResellerTransfer $transfer,
        EntitlementLot $source,
        int $take,
        array $targetAliases,
        string $reason
    ): EntitlementLot {
        $remaining = (int) $source->remaining_units - $take;

        $source->update([
            'remaining_units' => $remaining,
            'status' => $remaining === 0 ? 'DEPLETED' : $source->status,
        ]);

        $target = EntitlementLot::query()->create([
            'tenant_id' => $customer->requireTenant()->id,
            'user_id' => $customer->id,
            'source_type' => 'RESELLER_TRANSFER',
            'source_id' => $transfer->id,
            'package_name' => $source->package_name,
            'family_label' => $source->family_label,
            'billing_mode' => $source->billing_mode,
            'original_units' => $take,
            'remaining_units' => $take,
            'reserved_units' => 0,
            'unit_label' => $source->unit_label,
            'currency' => $source->currency,
            'currency_exponent' => $source->currency_exponent,
            'allowed_model_aliases' => array_values($targetAliases),
            'billing_snapshot' => $source->billing_snapshot,
            'billing_snapshot_hash' => $source->billing_snapshot_hash
                ?: hash(
                    'sha256',
                    json_encode(
                        $source->billing_snapshot ?? [],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                    )
                ),
            'access_scope' => 'ACCOUNT',
            'bound_api_key_id' => null,
            'fulfillment_claim_id' => null,
            'status' => 'ACTIVE',
            'activated_at' => now(),
            'expires_at' => $source->expires_at,
        ]);

        CreditLedger::query()->create([
            'user_id' => $reseller->id,
            'entitlement_lot_id' => $source->id,
            'type' => 'RESELLER_TRANSFER_OUT',
            'amount' => -$take,
            'idempotency_key' => "reseller-transfer-out:{$transfer->id}:{$source->id}",
            'source_type' => 'RESELLER_TRANSFER',
            'source_id' => $transfer->id,
            'actor_user_id' => $reseller->id,
            'reason' => $reason,
        ]);

        CreditLedger::query()->create([
            'user_id' => $customer->id,
            'entitlement_lot_id' => $target->id,
            'type' => 'RESELLER_TRANSFER_IN',
            'amount' => $take,
            'idempotency_key' => "reseller-transfer-in:{$transfer->id}:{$source->id}",
            'source_type' => 'RESELLER_TRANSFER',
            'source_id' => $transfer->id,
            'actor_user_id' => $reseller->id,
            'reason' => $reason,
        ]);

        return $target;
    }
}
