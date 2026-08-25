<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\Reservation;
use App\Models\ReservationAllocation;
use App\Models\PlaygroundCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReservationService
{
    /** @param array<int, string>|null $eligibleLotIds @param array<string, mixed>|null $billingSnapshot */
    public function reserve(User $user, string $publicAlias, string $billingMode, int $units, string $idempotencyKey, ?string $apiKeyId = null, ?array $eligibleLotIds = null, ?array $billingSnapshot = null, ?string $providerConnectionRevisionId = null): Reservation
    {
        if ($units <= 0) {
            throw new InvalidArgumentException('Reservation units must be positive.');
        }

        return DB::transaction(function () use ($user, $publicAlias, $billingMode, $units, $idempotencyKey, $apiKeyId, $eligibleLotIds, $billingSnapshot, $providerConnectionRevisionId): Reservation {
            $existing = Reservation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id
                    || $existing->api_key_id !== $apiKeyId
                    || $existing->public_model_alias !== $publicAlias
                    || $existing->billing_mode !== $billingMode
                    || (int) $existing->reserved_units !== $units
                    || ($billingSnapshot !== null && $existing->billing_snapshot !== $billingSnapshot)
                    || $existing->provider_connection_revision_id !== $providerConnectionRevisionId) {
                    throw new InferenceIdempotencyException;
                }

                return $existing->load('allocations');
            }
            $lotsQuery = EntitlementLot::query()->where('user_id', $user->id)->where('billing_mode', $billingMode)->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereJsonContains('allowed_model_aliases', $publicAlias);

            if ($apiKeyId !== null) {
                $isPlaygroundKey = PlaygroundCredential::query()
                    ->where('user_id', $user->id)
                    ->where('api_key_id', $apiKeyId)
                    ->exists();
                if (! $isPlaygroundKey) {
                    $lotsQuery->where('source_type', '!=', 'PLAYGROUND_DAILY');
                }
            }

            if ($eligibleLotIds !== null) {
                $lotsQuery->whereIn('id', $eligibleLotIds);
            }
            $lots = $lotsQuery->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('created_at')->lockForUpdate()->get();
            $available = $lots->sum(fn (EntitlementLot $lot): int => max(0, $lot->remaining_units - $lot->reserved_units));
            if ($available < $units) {
                throw new InsufficientBalanceException($billingMode);
            }
            $reservation = Reservation::query()->create(['user_id' => $user->id, 'api_key_id' => $apiKeyId, 'provider_connection_revision_id' => $providerConnectionRevisionId, 'public_model_alias' => $publicAlias, 'billing_mode' => $billingMode, 'reserved_units' => $units, 'billing_snapshot' => $billingSnapshot, 'status' => 'ACTIVE', 'idempotency_key' => $idempotencyKey, 'expires_at' => now()->addMinutes(15)]);
            $needed = $units;
            foreach ($lots as $lot) {
                $take = min($needed, max(0, $lot->remaining_units - $lot->reserved_units));
                if ($take === 0) {
                    continue;
                }
                $lot->increment('reserved_units', $take);
                ReservationAllocation::query()->create(['reservation_id' => $reservation->id, 'entitlement_lot_id' => $lot->id, 'reserved_units' => $take]);
                CreditLedger::query()->create(['user_id' => $user->id, 'entitlement_lot_id' => $lot->id, 'type' => 'RESERVATION', 'amount' => -$take, 'idempotency_key' => "reservation:{$reservation->id}:{$lot->id}", 'source_type' => 'RESERVATION', 'source_id' => $reservation->id]);
                $needed -= $take;
                if ($needed === 0) {
                    break;
                }
            }

            return $reservation->load('allocations');
        });
    }

    public function settle(Reservation $reservation, int $actualUnits): Reservation
    {
        return $this->finish($reservation, $actualUnits, 'SETTLED', ['ACTIVE', 'RECONCILIATION_REQUIRED']);
    }

    public function release(Reservation $reservation): Reservation
    {
        return $this->finish($reservation, 0, 'RELEASED');
    }

    public function expire(Reservation $reservation): Reservation
    {
        return $this->finish($reservation, 0, 'EXPIRED', ['ACTIVE', 'RECONCILIATION_REQUIRED']);
    }

    public function markForReconciliation(Reservation $reservation, string $reason): Reservation
    {
        if ($reason === '' || strlen($reason) > 100) {
            throw new InvalidArgumentException('A safe reconciliation reason is required.');
        }

        return DB::transaction(function () use ($reservation, $reason): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status === 'ACTIVE') {
                $locked->update([
                    'status' => 'RECONCILIATION_REQUIRED',
                    'reconciliation_reason' => $reason,
                    'reconciliation_requested_at' => now(),
                ]);
            }

            return $locked->fresh('allocations');
        });
    }

    public function recoverStale(int $batchSize = 100): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Recovery batch size must be between 1 and 1000.');
        }

        $reservationIds = Reservation::query()
            ->whereIn('status', ['ACTIVE', 'RECONCILIATION_REQUIRED'])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($batchSize)
            ->pluck('id');

        foreach ($reservationIds as $reservationId) {
            $this->expire(Reservation::query()->findOrFail($reservationId));
        }

        return $reservationIds->count();
    }

    /** @param array<int, string> $finalizableStatuses */
    private function finish(Reservation $reservation, int $actualUnits, string $finalStatus, array $finalizableStatuses = ['ACTIVE']): Reservation
    {
        if ($actualUnits < 0 || $actualUnits > $reservation->reserved_units) {
            throw new InvalidArgumentException('Actual units must be within the reserved amount.');
        }

        return DB::transaction(function () use ($reservation, $actualUnits, $finalStatus, $finalizableStatuses): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, $finalizableStatuses, true)) {
                if ($locked->status !== $finalStatus) {
                    throw new InferenceIdempotencyException('Reservation was already finalized by a different operation.');
                }
                if ((int) $locked->settled_units !== $actualUnits) {
                    throw new InferenceIdempotencyException('Reservation is already finalized with different usage.');
                }

                return $locked->load('allocations');
            }
            $remainingActual = $actualUnits;
            foreach ($locked->allocations()->orderBy('id')->get() as $allocation) {
                $settled = min($remainingActual, $allocation->reserved_units);
                $lot = EntitlementLot::query()->lockForUpdate()->findOrFail($allocation->entitlement_lot_id);
                $unused = $allocation->reserved_units - $settled;
                $expiredUnused = $lot->status === 'EXPIRED' ? $unused : 0;
                $remainingUnits = $lot->remaining_units - $settled - $expiredUnused;
                $lot->update([
                    'reserved_units' => $lot->reserved_units - $allocation->reserved_units,
                    'remaining_units' => $remainingUnits,
                    'status' => $lot->status === 'EXPIRED' ? 'EXPIRED' : ($remainingUnits === 0 ? 'DEPLETED' : $lot->status),
                ]);
                $allocation->update(['settled_units' => $settled]);
                CreditLedger::query()->create(['user_id' => $locked->user_id, 'entitlement_lot_id' => $lot->id, 'type' => 'RESERVATION_RELEASE', 'amount' => $allocation->reserved_units, 'idempotency_key' => "reservation-release:{$locked->id}:{$lot->id}", 'source_type' => 'RESERVATION', 'source_id' => $locked->id]);
                if ($settled > 0) {
                    CreditLedger::query()->create(['user_id' => $locked->user_id, 'entitlement_lot_id' => $lot->id, 'type' => 'USAGE', 'amount' => -$settled, 'idempotency_key' => "usage:{$locked->id}:{$lot->id}", 'source_type' => 'RESERVATION', 'source_id' => $locked->id]);
                }
                if ($expiredUnused > 0) {
                    CreditLedger::query()->create(['user_id' => $locked->user_id, 'entitlement_lot_id' => $lot->id, 'type' => 'EXPIRATION', 'amount' => -$expiredUnused, 'idempotency_key' => "reservation-expiration:{$locked->id}:{$lot->id}", 'source_type' => 'EXPIRATION', 'source_id' => $locked->id]);
                }
                $remainingActual -= $settled;
            }
            $locked->update([
                'status' => $finalStatus,
                'settled_units' => $actualUnits,
                'settled_at' => now(),
                'reconciliation_reason' => null,
                'reconciliation_requested_at' => null,
            ]);

            return $locked->fresh('allocations');
        });
    }
}
