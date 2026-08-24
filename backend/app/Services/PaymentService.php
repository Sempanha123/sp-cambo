<?php

namespace App\Services;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\MoneyDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly KhqrGenerator $generator,
        private readonly BakongVerifier $verifier,
        private readonly MoneyDecimal $money,
        private readonly OrderFulfillmentService $fulfillment
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

            $current = $locked->paymentAttempts()->latest()->first();

            if ($current
                && in_array($current->status, ['PENDING', 'VERIFYING'], true)
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
        $ready = DB::transaction(function () use ($attempt): PaymentAttempt {
            $locked = PaymentAttempt::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ($locked->status === 'PAID') {
                return $locked;
            }

            // Even an expired local attempt gets one more real Bakong lookup.
            // This recovers payments that succeeded before expiry while SP Cambo
            // temporarily could not verify/fulfill them.
            $locked->update([
                'status' => 'VERIFYING',
                'last_checked_at' => now(),
            ]);

            $locked->order()
                ->whereIn('status', ['PENDING_PAYMENT', 'VERIFYING'])
                ->update(['status' => 'VERIFYING']);

            return $locked->fresh('order');
        });

        if ($ready->status === 'PAID') {
            return $ready;
        }

        $wasExpired = $ready->expires_at->isPast();

        try {
            $evidence = $this->verifier->checkByMd5($ready->qr_md5);
        } catch (Throwable $exception) {
            $this->restoreAwaitingState($ready, $wasExpired);
            throw $exception;
        }

        if (! $evidence['found']) {
            $this->restoreAwaitingState($ready, $wasExpired);

            return $ready->fresh('order');
        }

        try {
            return DB::transaction(function () use ($ready, $evidence): PaymentAttempt {
                $locked = PaymentAttempt::query()
                    ->with(['order.items'])
                    ->lockForUpdate()
                    ->findOrFail($ready->id);

                if ($locked->status === 'PAID') {
                    return $locked;
                }

                $expectedAccount = (string) config('services.bakong.account_id');

                if (! is_string($evidence['to_account_id'])
                    || ! is_string($evidence['currency'])
                    || ! is_string($evidence['amount_decimal'])
                    || ! is_string($evidence['transaction_hash'])) {
                    throw new PaymentException(
                        'payment_verification_failed',
                        'Bakong returned incomplete payment evidence.',
                        422
                    );
                }

                $actualMinor = $this->money->toMinor(
                    $evidence['amount_decimal'],
                    (int) $locked->currency_exponent
                );

                if (! hash_equals($expectedAccount, $evidence['to_account_id'])
                    || strtoupper($evidence['currency']) !== $locked->currency
                    || $actualMinor !== (int) $locked->amount_minor) {
                    throw new PaymentException(
                        'payment_verification_failed',
                        'Verified payment evidence does not match this order.',
                        422
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

                $locked->update([
                    'status' => 'PAID',
                    'transaction_hash' => $evidence['transaction_hash'],
                    'last_checked_at' => now(),
                    'paid_at' => now(),
                ]);

                $this->fulfillment->fulfill($locked->order);

                return $locked->fresh('order');
            });
        } catch (QueryException $exception) {
            $this->restoreAwaitingState($ready, $wasExpired);

            if ($exception->getCode() === '23000') {
                throw new PaymentException(
                    'payment_replayed',
                    'This payment transaction was already used.',
                    409
                );
            }

            throw $exception;
        } catch (Throwable $exception) {
            // Fulfillment failure must not strand UI/order in VERIFYING.
            $this->restoreAwaitingState($ready, $wasExpired);
            throw $exception;
        }
    }

    private function restoreAwaitingState(
        PaymentAttempt $attempt,
        bool $expired
    ): void {
        DB::transaction(function () use ($attempt, $expired): void {
            $locked = PaymentAttempt::query()
                ->lockForUpdate()
                ->find($attempt->id);

            if (! $locked || $locked->status === 'PAID') {
                return;
            }

            $locked->update([
                'status' => $expired ? 'EXPIRED' : 'PENDING',
                'last_checked_at' => now(),
            ]);

            $locked->order()
                ->where('status', 'VERIFYING')
                ->update(['status' => 'PENDING_PAYMENT']);
        });
    }

    /** @return array{checked: int, failed: int} */
    public function reconcilePending(int $batchSize = 1): array
    {
        if ($batchSize < 1 || $batchSize > 4) {
            throw new \InvalidArgumentException(
                'Payment reconciliation batch must be between 1 and 4.'
            );
        }

        $ids = PaymentAttempt::query()
            ->where('status', 'PENDING')
            ->where('expires_at', '>', now())
            ->where(
                fn ($query) => $query
                    ->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subMinutes(15))
            )
            ->orderByRaw('last_checked_at IS NOT NULL')
            ->orderBy('last_checked_at')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->pluck('id');

        $failed = 0;

        foreach ($ids as $id) {
            try {
                $this->verify(PaymentAttempt::query()->findOrFail($id));
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return [
            'checked' => $ids->count(),
            'failed' => $failed,
        ];
    }
}
