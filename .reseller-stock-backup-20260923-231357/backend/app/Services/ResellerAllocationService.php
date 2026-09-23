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
use InvalidArgumentException;

class ResellerAllocationService
{
    public function allocate(User $reseller, User $customer, string $billingMode, string $publicAlias, int $units, string $idempotencyKey, string $reason): ResellerTransfer
    {
        if ($units <= 0 || mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException('Allocation units and audit reason are invalid.');
        }

        return DB::transaction(function () use ($reseller, $customer, $billingMode, $publicAlias, $units, $idempotencyKey, $reason): ResellerTransfer {
            if (! ResellerCustomer::query()->where('reseller_user_id', $reseller->id)->where('customer_user_id', $customer->id)->where('status', 'ACTIVE')->exists()) {
                throw new InvalidArgumentException('Customer is not actively managed by this reseller.');
            }
            $existing = ResellerTransfer::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->reseller_user_id !== (int) $reseller->id || (int) $existing->customer_user_id !== (int) $customer->id || $existing->billing_mode !== $billingMode || $existing->public_model_alias !== $publicAlias || (int) $existing->units !== $units) {
                    throw new InferenceIdempotencyException('The allocation idempotency key was already used for different inputs.');
                }

                return $existing->load('allocations');
            }
            $lots = EntitlementLot::query()->where('user_id', $reseller->id)->where('billing_mode', $billingMode)->where('status', 'ACTIVE')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->whereJsonContains('allowed_model_aliases', $publicAlias)->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('created_at')->lockForUpdate()->get();
            $available = $lots->sum(fn (EntitlementLot $lot): int => max(0, $lot->remaining_units - $lot->reserved_units));
            if ($available < $units) {
                throw new InsufficientBalanceException($billingMode);
            }
            $transfer = ResellerTransfer::query()->create(['reseller_user_id' => $reseller->id, 'customer_user_id' => $customer->id, 'billing_mode' => $billingMode, 'public_model_alias' => $publicAlias, 'units' => $units, 'idempotency_key' => $idempotencyKey, 'reason' => $reason]);
            $needed = $units;
            foreach ($lots as $source) {
                $take = min($needed, max(0, $source->remaining_units - $source->reserved_units));
                if ($take === 0) {
                    continue;
                }
                $remaining = $source->remaining_units - $take;
                $source->update(['remaining_units' => $remaining, 'status' => $remaining === 0 ? 'DEPLETED' : $source->status]);
                $target = EntitlementLot::query()->create(['user_id' => $customer->id, 'source_type' => 'RESELLER_TRANSFER', 'source_id' => $transfer->id, 'package_name' => $source->package_name, 'family_label' => $source->family_label, 'billing_mode' => $source->billing_mode, 'original_units' => $take, 'remaining_units' => $take, 'reserved_units' => 0, 'unit_label' => $source->unit_label, 'currency' => $source->currency, 'currency_exponent' => $source->currency_exponent, 'allowed_model_aliases' => [$publicAlias], 'billing_snapshot' => $source->billing_snapshot, 'status' => 'ACTIVE', 'activated_at' => now(), 'expires_at' => $source->expires_at]);
                ResellerTransferAllocation::query()->create(['reseller_transfer_id' => $transfer->id, 'source_entitlement_lot_id' => $source->id, 'target_entitlement_lot_id' => $target->id, 'units' => $take]);
                CreditLedger::query()->create(['user_id' => $reseller->id, 'entitlement_lot_id' => $source->id, 'type' => 'RESELLER_TRANSFER_OUT', 'amount' => -$take, 'idempotency_key' => "reseller-transfer-out:{$transfer->id}:{$source->id}", 'source_type' => 'RESELLER_TRANSFER', 'source_id' => $transfer->id, 'actor_user_id' => $reseller->id, 'reason' => $reason]);
                CreditLedger::query()->create(['user_id' => $customer->id, 'entitlement_lot_id' => $target->id, 'type' => 'RESELLER_TRANSFER_IN', 'amount' => $take, 'idempotency_key' => "reseller-transfer-in:{$transfer->id}:{$source->id}", 'source_type' => 'RESELLER_TRANSFER', 'source_id' => $transfer->id, 'actor_user_id' => $reseller->id, 'reason' => $reason]);
                $needed -= $take;
                if ($needed === 0) {
                    break;
                }
            }

            return $transfer->load('allocations');
        });
    }
}
