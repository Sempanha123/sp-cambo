<?php

namespace App\Services;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Exceptions\PaymentException;
use App\Exceptions\PackageStockException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\MoneyDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly KhqrGenerator $generator,
        private readonly BakongVerifier $verifier,
        private readonly MoneyDecimal $money,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly PackageStockService $stock,
    ) {}

    public function createAttempt(Order $order): PaymentAttempt
    {
        return DB::transaction(function () use ($order): PaymentAttempt {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, ['PENDING_PAYMENT', 'VERIFYING'], true)) {
                throw new PaymentException(
                    'payment_not_available',
                    'This order is not awaiting payment.'
                );
            }

            try {
                $locked = $this->stock->reserveForOrder($locked);
            } catch (PackageStockException $exception) {
                throw new PaymentException('package_out_of_stock', $exception->getMessage(), 409);
            }

            $current = $locked->paymentAttempts()->latest()->first();

            if ($current && $current->status === 'VERIFYING') {
                // Never mint a second payable QR while verification/recovery owns the
                // previous attempt. The scheduler can reclaim a stale verification
                // lease and finish this attempt safely.
                return $current;
            }

            if ($current
                && $current->status === 'PENDING'
                && $current->expires_at->isFuture()) {
                return $current;
            }

            if ($current && $current->status !== 'PAID') {
                $current->update(['status' => 'EXPIRED']);
            }

            $accountId = (string) config('services.bakong.account_id');
            $merchant = (string) config('services.bakong.merchant_name');
            $city = (string) config('services.bakong.merchant_city');

            if ($accountId === '' || $merchant === '' || $city === '') {
                throw new PaymentException(
                    'payment_unavailable',
                    'Payment service is not configured.',
                    503
                );
            }

            $amount = $this->money->fromMinor(
                (int) $locked->total_minor,
                (int) $locked->currency_exponent
            );

            // Bakong billNumber/reference supports max 25 characters.
            // Keep the complete SP Cambo order reference in our DB/UI.
            $khqrReference = substr((string) $locked->reference, 0, 25);

            $generated = $this->generator->generate(
                $accountId,
                $merchant,
                $city,
                $locked->currency,
                $amount,
                $khqrReference
            );

            return $locked->paymentAttempts()->create([
                'status' => 'PENDING',
                'qr_payload' => $generated['qr_payload'],
                'qr_md5' => $generated['md5'],
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'currency_exponent' => $locked->currency_exponent,
                'expires_at' => now()->addSeconds(
                    (int) config('services.bakong.attempt_ttl_seconds', 300)
                ),
            ]);
        });
    }

    public function verify(PaymentAttempt $attempt): PaymentAttempt
    {
        $claim = DB::transaction(function () use ($attempt): array {
            $locked = PaymentAttempt::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ($locked->status === 'PAID') {
                return ['attempt' => $locked, 'lease' => null, 'claimed' => false];
            }

            if ($locked->status === 'VERIFYING'
                && $locked->verification_lease_expires_at !== null
                && $locked->verification_lease_expires_at->isFuture()) {
                // Another worker is performing the external Bakong lookup. Returning
                // the current state is intentionally idempotent and avoids a second
                // lookup for the same QR.
                return ['attempt' => $locked, 'lease' => null, 'claimed' => false];
            }

            $lease = hash('sha256', Str::random(64));
            $leaseSeconds = max(30, (int) config('services.bakong.verification_lease_seconds', 90));

            // Even an expired local attempt gets one more real Bakong lookup. This
            // recovers payments that succeeded before expiry while SP Cambo was
            // temporarily unavailable.
            $locked->forceFill([
                'status' => 'VERIFYING',
                'last_checked_at' => now(),
                'verification_lease_token' => $lease,
                'verification_lease_expires_at' => now()->addSeconds($leaseSeconds),
            ])->save();

            $locked->order()
                ->whereIn('status', ['PENDING_PAYMENT', 'VERIFYING'])
                ->update(['status' => 'VERIFYING']);

            return ['attempt' => $locked->fresh('order'), 'lease' => $lease, 'claimed' => true];
        });

        /** @var PaymentAttempt $ready */
        $ready = $claim['attempt'];
        $lease = $claim['lease'];

        if ($ready->status === 'PAID' || ! $claim['claimed'] || ! is_string($lease)) {
            return $ready;
        }

        $wasExpired = $ready->expires_at->isPast();

        try {
            $evidence = $this->verifier->checkByMd5($ready->qr_md5);
        } catch (Throwable $exception) {
            $this->restoreAwaitingState($ready, $wasExpired, $lease);
            // Keep the customer-facing message intentionally generic, but record
            // the safe upstream reason for operators. Never log the token, QR
            // payload, or transaction digest.
            logger()->warning('Bakong verification request failed', [
                'attempt_id' => (string) $ready->id,
                'exception_class' => $exception::class,
                'provider_error' => substr($exception->getMessage(), 0, 240),
            ]);
            report($exception);

            if ($exception instanceof PaymentException) {
                throw $exception;
            }

            throw new PaymentException(
                'payment_verification_unavailable',
                'SP Cambo could not verify this transfer with Bakong right now. Your order remains pending; do not pay again. Please retry shortly.',
                503,
                $exception->getMessage(),
            );
        }

        if (! $evidence['found']) {
            $this->restoreAwaitingState($ready, $wasExpired, $lease);

            return $ready->fresh('order');
        }

        try {
            return DB::transaction(function () use ($ready, $evidence, $lease): PaymentAttempt {
                $locked = PaymentAttempt::query()
                    ->with(['order.items'])
                    ->lockForUpdate()
                    ->findOrFail($ready->id);

                if ($locked->status === 'PAID') {
                    return $locked;
                }

                // A stale worker is not allowed to settle after another worker has
                // reclaimed the verification lease.
                if (! hash_equals((string) $locked->verification_lease_token, $lease)) {
                    return $locked->fresh('order');
                }

                $expectedAccount = (string) config('services.bakong.account_id');

                if (! is_string($evidence['to_account_id'])
                    || ! is_string($evidence['currency'])
                    || ! is_string($evidence['amount_decimal'])
                    || ! is_string($evidence['transaction_hash'])) {
                    throw new PaymentException(
                        'payment_verification_failed',
                        'Bakong returned incomplete payment evidence.',
                        422,
                        'Bakong returned responseCode=0 but one or more required transaction evidence fields were missing.',
                    );
                }

                $actualMinor = $this->money->toMinor(
                    $evidence['amount_decimal'],
                    (int) $locked->currency_exponent
                );

                $expectedAccountCanonical = strtolower(trim($expectedAccount));
                $paidAccountCanonical = strtolower(trim($evidence['to_account_id']));

                $accountMatches = $expectedAccountCanonical !== ''
                    && hash_equals($expectedAccountCanonical, $paidAccountCanonical);
                $currencyMatches = strtoupper(trim($evidence['currency'])) === $locked->currency;
                $amountMatches = $actualMinor === (int) $locked->amount_minor;

                if (! $accountMatches || ! $currencyMatches || ! $amountMatches) {
                    throw new PaymentException(
                        'payment_verification_failed',
                        'Verified payment evidence does not match this order.',
                        422,
                        sprintf(
                            'Bakong payment evidence mismatch: recipient=%s, currency=%s, amount=%s.',
                            $accountMatches ? 'match' : 'mismatch',
                            $currencyMatches ? 'match' : 'mismatch',
                            $amountMatches ? 'match' : 'mismatch',
                        ),
                    );
                }

                $replay = PaymentAttempt::query()
                    ->where('transaction_hash', $evidence['transaction_hash'])
                    ->whereKeyNot($locked->id)
                    ->exists();

                if ($replay) {
                    throw new PaymentException(
                        'payment_replayed',
                        'This payment transaction was already used.',
                        409
                    );
                }

                $locked->forceFill([
                    'status' => 'PAID',
                    'transaction_hash' => $evidence['transaction_hash'],
                    'last_checked_at' => now(),
                    'paid_at' => now(),
                    'verification_lease_token' => null,
                    'verification_lease_expires_at' => null,
                ])->save();

                $this->fulfillment->fulfill($locked->order);

                return $locked->fresh('order');
            });
        } catch (QueryException $exception) {
            $this->restoreAwaitingState($ready, $wasExpired, $lease);

            if ($exception->getCode() === '23000') {
                throw new PaymentException(
                    'payment_replayed',
                    'This payment transaction was already used.',
                    409
                );
            }

            throw $exception;
        } catch (PaymentException $exception) {
            // Evidence mismatch/replay stays a precise domain error. Never leave
            // the attempt in VERIFYING after rejecting the evidence.
            $this->restoreAwaitingState($ready, $wasExpired, $lease);
            throw $exception;
        } catch (Throwable $exception) {
            // Bakong evidence was found but fulfillment failed. Keep the purchase
            // recoverable and tell the customer not to pay a second time. The
            // detailed exception remains server-side.
            $this->restoreAwaitingState($ready, $wasExpired, $lease);
            report($exception);

            throw new PaymentException(
                'payment_fulfillment_recovery_required',
                'SP Cambo found the payment, but access delivery needs recovery. Do not pay again; the payment can be safely re-checked.',
                503,
                'Order fulfillment failed after Bakong returned matching payment evidence. Inspect the server log for the underlying exception.',
            );
        }
    }

    public function verifyIfDue(PaymentAttempt $attempt): PaymentAttempt
    {
        $fresh = PaymentAttempt::query()->with('order')->findOrFail($attempt->id);

        if ($fresh->status === 'PAID') {
            return $fresh;
        }

        if ($fresh->status === 'VERIFYING'
            && $fresh->verification_lease_expires_at !== null
            && $fresh->verification_lease_expires_at->isFuture()) {
            return $fresh;
        }

        if (! in_array($fresh->status, ['PENDING', 'EXPIRED', 'VERIFYING'], true)) {
            return $fresh;
        }

        $expiredGraceSeconds = max(0, (int) config('services.bakong.reconcile_expired_grace_seconds', 900));
        if ($fresh->expires_at->lt(now()->subSeconds($expiredGraceSeconds))) {
            return $fresh;
        }

        $intervalSeconds = max(15, (int) config('services.bakong.customer_auto_check_interval_seconds', 15));
        if ($fresh->last_checked_at !== null
            && $fresh->last_checked_at->gt(now()->subSeconds($intervalSeconds))) {
            return $fresh;
        }

        return $this->verify($fresh);
    }

    private function restoreAwaitingState(
        PaymentAttempt $attempt,
        bool $expired,
        string $lease
    ): void {
        DB::transaction(function () use ($attempt, $expired, $lease): void {
            $locked = PaymentAttempt::query()
                ->lockForUpdate()
                ->find($attempt->id);

            if (! $locked || $locked->status === 'PAID') {
                return;
            }

            if (! hash_equals((string) $locked->verification_lease_token, $lease)) {
                return;
            }

            $locked->forceFill([
                'status' => $expired ? 'EXPIRED' : 'PENDING',
                'last_checked_at' => now(),
                'verification_lease_token' => null,
                'verification_lease_expires_at' => null,
            ])->save();

            $locked->order()
                ->where('status', 'VERIFYING')
                ->update(['status' => 'PENDING_PAYMENT']);
        });
    }

    /** @return array{checked:int, settled:int, waiting:int, failed:int, errors:array<int,array{attempt_id:string,code:string,message:string}>} */
    public function reconcilePending(int $batchSize = 1): array
    {
        if ($batchSize < 1 || $batchSize > 4) {
            throw new \InvalidArgumentException(
                'Payment reconciliation batch must be between 1 and 4.'
            );
        }

        $intervalSeconds = max(15, (int) config('services.bakong.reconcile_interval_seconds', 60));
        $expiredGraceSeconds = max(0, (int) config('services.bakong.reconcile_expired_grace_seconds', 900));

        $ids = PaymentAttempt::query()
            ->where('expires_at', '>=', now()->subSeconds($expiredGraceSeconds))
            ->where(function ($query) use ($intervalSeconds): void {
                $query->where(function ($pending) use ($intervalSeconds): void {
                    $pending->whereIn('status', ['PENDING', 'EXPIRED'])
                        ->where(function ($due) use ($intervalSeconds): void {
                            $due->whereNull('last_checked_at')
                                ->orWhere('last_checked_at', '<=', now()->subSeconds($intervalSeconds));
                        });
                })->orWhere(function ($stale): void {
                    $stale->where('status', 'VERIFYING')
                        ->where(function ($lease): void {
                            $lease->whereNull('verification_lease_expires_at')
                                ->orWhere('verification_lease_expires_at', '<=', now());
                        });
                });
            })
            ->orderByRaw('last_checked_at IS NOT NULL')
            ->orderBy('last_checked_at')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->pluck('id');

        $settled = 0;
        $waiting = 0;
        $failed = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $verified = $this->verify(PaymentAttempt::query()->findOrFail($id));
                if ($verified->status === 'PAID' || $verified->order?->status === 'FULFILLED') {
                    $settled++;
                } else {
                    $waiting++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;

                $errors[] = [
                    'attempt_id' => (string) $id,
                    'code' => $exception instanceof PaymentException
                        ? $exception->errorCode
                        : class_basename($exception),
                    'message' => $exception instanceof PaymentException
                        ? ($exception->operatorMessage ?? $exception->getMessage())
                        : 'Unexpected reconciliation failure. Inspect the Laravel log for details.',
                ];
            }
        }

        return [
            'checked' => $ids->count(),
            'settled' => $settled,
            'waiting' => $waiting,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
