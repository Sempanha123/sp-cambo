<?php

namespace App\Services;

use App\Events\CustomerStateChanged;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EntitlementService
{
    public function grant(User $user, array $snapshot, string $idempotencyKey): EntitlementLot
    {
        return DB::transaction(function () use ($user, $snapshot, $idempotencyKey): EntitlementLot {
            $tenant = $user->requireTenant();
            $existing = CreditLedger::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return EntitlementLot::query()->findOrFail($existing->entitlement_lot_id);
            }
            $activatedAt = $snapshot['activated_at'] ?? now();
            $billingSnapshot = $snapshot['billing_snapshot'] ?? [];
            $snapshot['billing_snapshot_hash'] = hash('sha256', json_encode($billingSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $lot = EntitlementLot::query()->create($snapshot + ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'remaining_units' => $snapshot['original_units'], 'reserved_units' => 0, 'status' => 'ACTIVE', 'activated_at' => $activatedAt]);
            $ledgerType = match ($snapshot['source_type']) {
                'ORDER' => 'PURCHASE',
                'PROMOTION' => 'PROMOTION',
                'REDEEM_CODE' => 'PROMOTION',
                'PLAYGROUND_DAILY' => 'PROMOTION',
                default => 'ADMIN_ADJUSTMENT',
            };
            CreditLedger::query()->create(['user_id' => $user->id, 'entitlement_lot_id' => $lot->id, 'type' => $ledgerType, 'amount' => $snapshot['original_units'], 'idempotency_key' => $idempotencyKey, 'source_type' => $snapshot['source_type'], 'source_id' => $snapshot['source_id'] ?? null, 'reason' => $snapshot['reason'] ?? null]);

            return $lot;
        });
    }

    public function expire(EntitlementLot $lot): EntitlementLot
    {
        return DB::transaction(function () use ($lot): EntitlementLot {
            $locked = EntitlementLot::query()->lockForUpdate()->findOrFail($lot->id);
            if ($locked->status !== 'ACTIVE' || ! $locked->expires_at?->isPast()) {
                return $locked;
            }
            $forfeited = $locked->remaining_units - $locked->reserved_units;
            $locked->update(['remaining_units' => $locked->reserved_units, 'status' => 'EXPIRED']);
            CreditLedger::query()->firstOrCreate(['idempotency_key' => "expiration:{$locked->id}"], ['user_id' => $locked->user_id, 'entitlement_lot_id' => $locked->id, 'type' => 'EXPIRATION', 'amount' => -$forfeited, 'source_type' => 'EXPIRATION', 'source_id' => $locked->id]);
            CustomerStateChanged::dispatch((int) $locked->user_id, 'entitlement.expired', [
                'entitlement_id' => $locked->id,
                'status' => 'EXPIRED',
            ]);

            return $locked->fresh();
        });
    }

    public function expireDue(int $batchSize = 100): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Expiration batch size must be between 1 and 1000.');
        }

        $lotIds = EntitlementLot::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        $expired = 0;
        foreach ($lotIds as $lotId) {
            $lot = $this->expire(EntitlementLot::query()->findOrFail($lotId));
            if ($lot->status === 'EXPIRED') {
                $expired++;
            }
        }

        return $expired;
    }
}
