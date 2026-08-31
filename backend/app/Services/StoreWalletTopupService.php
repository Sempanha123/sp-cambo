<?php

namespace App\Services;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Models\PaymentAttempt;
use App\Models\StoreWalletTopup;
use App\Models\TelegramAccount;
use App\Services\Payments\MoneyDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreWalletTopupService
{
    public function __construct(
        private readonly KhqrGenerator $khqr,
        private readonly BakongVerifier $bakong,
        private readonly MoneyDecimal $money,
        private readonly StoreWalletService $wallets,
        private readonly TelegramBotClient $bot,
    ) {}

    /** @return int[] */
    public function presetAmountsMinor(): array
    {
        $spec = $this->wallets->currencySpec();

        return $spec['currency'] === 'KHR'
            ? [4_000, 20_000, 40_000, 80_000, 200_000]
            : [100, 500, 1_000, 2_000, 5_000];
    }

    public function create(TelegramAccount $account, int $amountMinor): StoreWalletTopup
    {
        if (! in_array($amountMinor, $this->presetAmountsMinor(), true)) {
            throw new RuntimeException('That Store Wallet top-up amount is not available.');
        }

        $spec = $this->wallets->currencySpec();
        $accountId = trim((string) config('services.bakong.account_id'));
        $merchantName = trim((string) config('services.bakong.merchant_name'));
        $merchantCity = trim((string) config('services.bakong.merchant_city'));

        if ($accountId === '' || $merchantName === '' || $merchantCity === '') {
            throw new RuntimeException('Bakong KHQR merchant settings are not configured.');
        }

        $reference = 'SPW-'.substr((string) Str::ulid(), -20);
        $amountDecimal = $this->money->fromMinor($amountMinor, $spec['exponent']);
        $generated = $this->khqr->generate(
            $accountId,
            $merchantName,
            $merchantCity,
            $spec['currency'],
            $amountDecimal,
            $reference,
        );

        return StoreWalletTopup::query()->create([
            'user_id' => $account->user_id,
            'telegram_account_id' => $account->id,
            'currency' => $spec['currency'],
            'currency_exponent' => $spec['exponent'],
            'amount_minor' => $amountMinor,
            'reference' => $reference,
            'status' => 'PENDING',
            'qr_payload' => $generated['qr_payload'],
            'qr_md5' => $generated['md5'],
            'expires_at' => now()->addSeconds((int) config('services.bakong.attempt_ttl_seconds', 300)),
        ]);
    }

    public function verify(StoreWalletTopup $topup): StoreWalletTopup
    {
        if ($topup->status === 'PAID') {
            return $topup;
        }

        $lease = $this->claimLease((string) $topup->id);
        if ($lease === null) {
            return StoreWalletTopup::query()->findOrFail($topup->id);
        }

        try {
            $evidence = $this->bakong->checkByMd5((string) $topup->qr_md5);

            if (! $evidence['found']) {
                return $this->finishWaiting((string) $topup->id, $lease);
            }

            $this->validateEvidence($topup, $evidence);
            $hash = trim((string) ($evidence['transaction_hash'] ?? ''));
            if ($hash === '') {
                throw new RuntimeException('Bakong verification did not return a transaction hash.');
            }

            $paid = DB::transaction(function () use ($topup, $lease, $hash): StoreWalletTopup {
                $locked = StoreWalletTopup::query()->lockForUpdate()->findOrFail($topup->id);
                if ($locked->status === 'PAID') {
                    return $locked;
                }
                if (! hash_equals((string) $locked->verification_lease_token, $lease)) {
                    return $locked;
                }

                $paymentReplay = PaymentAttempt::query()
                    ->where('transaction_hash', $hash)
                    ->exists();
                $topupReplay = StoreWalletTopup::query()
                    ->where('transaction_hash', $hash)
                    ->where('id', '!=', $locked->id)
                    ->exists();

                if ($paymentReplay || $topupReplay) {
                    throw new RuntimeException('This Bakong transaction has already been consumed by SP Cambo.');
                }

                $locked->forceFill([
                    'status' => 'PAID',
                    'transaction_hash' => $hash,
                    'paid_at' => now(),
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'verification_lease_token' => null,
                    'verification_lease_expires_at' => null,
                ])->save();

                $this->wallets->credit(
                    $locked->user()->firstOrFail(),
                    (int) $locked->amount_minor,
                    'store-wallet:topup:'.$locked->id,
                    'STORE_WALLET_TOPUP',
                    (string) $locked->id,
                    ['bakong_transaction_hash' => $hash],
                );

                return $locked->fresh();
            });

            if ($paid->status === 'PAID') {
                $this->notifyPaid($paid);
            }

            return $paid;
        } catch (Throwable $exception) {
            $this->releaseLeaseWithError((string) $topup->id, $lease, $exception);
            throw $exception;
        }
    }

    /** @return array{checked:int,settled:int,waiting:int,failed:int} */
    public function reconcilePending(int $batch = 1): array
    {
        $batch = max(1, min($batch, 2));
        $interval = max(15, (int) config('services.bakong.reconcile_interval_seconds', 60));

        $ids = StoreWalletTopup::query()
            ->whereIn('status', ['PENDING', 'VERIFYING'])
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($interval): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subSeconds($interval));
            })
            ->orderBy('created_at')
            ->limit($batch)
            ->pluck('id');

        $result = ['checked' => 0, 'settled' => 0, 'waiting' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $result['checked']++;
            try {
                $topup = $this->verify(StoreWalletTopup::query()->findOrFail($id));
                if ($topup->status === 'PAID') {
                    $result['settled']++;
                } else {
                    $result['waiting']++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function claimLease(string $id): ?string
    {
        return DB::transaction(function () use ($id): ?string {
            $locked = StoreWalletTopup::query()->lockForUpdate()->findOrFail($id);
            if ($locked->status === 'PAID') {
                return null;
            }

            if (
                $locked->verification_lease_token
                && $locked->verification_lease_expires_at
                && $locked->verification_lease_expires_at->isFuture()
            ) {
                return null;
            }

            $token = Str::random(48);
            $locked->forceFill([
                'status' => 'VERIFYING',
                'verification_lease_token' => $token,
                'verification_lease_expires_at' => now()->addSeconds(
                    max(30, (int) config('services.bakong.verification_lease_seconds', 90))
                ),
                'last_checked_at' => now(),
            ])->save();

            return $token;
        });
    }

    private function finishWaiting(string $id, string $lease): StoreWalletTopup
    {
        return DB::transaction(function () use ($id, $lease): StoreWalletTopup {
            $locked = StoreWalletTopup::query()->lockForUpdate()->findOrFail($id);
            if (! hash_equals((string) $locked->verification_lease_token, $lease)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $locked->expires_at->isPast() ? 'EXPIRED' : 'PENDING',
                'last_checked_at' => now(),
                'verification_lease_token' => null,
                'verification_lease_expires_at' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    private function releaseLeaseWithError(string $id, string $lease, Throwable $exception): void
    {
        DB::transaction(function () use ($id, $lease, $exception): void {
            $locked = StoreWalletTopup::query()->lockForUpdate()->find($id);
            if (! $locked || ! hash_equals((string) $locked->verification_lease_token, $lease)) {
                return;
            }

            $locked->forceFill([
                'status' => $locked->expires_at->isPast() ? 'EXPIRED' : 'PENDING',
                'last_checked_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'verification_lease_token' => null,
                'verification_lease_expires_at' => null,
            ])->save();
        });
    }

    /** @param array{found:bool,transaction_hash:string|null,to_account_id:string|null,currency:string|null,amount_decimal:string|null} $evidence */
    private function validateEvidence(StoreWalletTopup $topup, array $evidence): void
    {
        $expectedAccount = mb_strtolower(trim((string) config('services.bakong.account_id')));
        $actualAccount = mb_strtolower(trim((string) ($evidence['to_account_id'] ?? '')));
        if ($expectedAccount === '' || $actualAccount === '' || ! hash_equals($expectedAccount, $actualAccount)) {
            throw new RuntimeException('Bakong payment destination does not match the SP Cambo merchant account.');
        }

        $currency = strtoupper(trim((string) ($evidence['currency'] ?? '')));
        if (! hash_equals((string) $topup->currency, $currency)) {
            throw new RuntimeException('Bakong payment currency does not match the Store Wallet top-up.');
        }

        $amount = trim((string) ($evidence['amount_decimal'] ?? ''));
        if ($amount === '' || $this->money->toMinor($amount, (int) $topup->currency_exponent) !== (int) $topup->amount_minor) {
            throw new RuntimeException('Bakong payment amount does not match the Store Wallet top-up.');
        }
    }

    private function notifyPaid(StoreWalletTopup $topup): void
    {
        $account = $topup->telegramAccount()->first();
        if (! $account) {
            return;
        }

        $km = strtolower((string) $account->locale) === 'km';
        $amount = $this->money->fromMinor((int) $topup->amount_minor, (int) $topup->currency_exponent);
        $balance = $this->wallets->balanceMinor(
            $topup->user()->firstOrFail(),
            (string) $topup->currency,
            (int) $topup->currency_exponent,
        );
        $balanceText = $this->money->fromMinor($balance, (int) $topup->currency_exponent);

        try {
            $this->bot->sendMessage(
                (string) $account->chat_id,
                $km
                    ? "✅✨ បញ្ចូលប្រាក់ជោគជ័យ!\n\n💵 +{$amount} {$topup->currency}\n👛 សមតុល្យ Store Wallet: {$balanceText} {$topup->currency}\n\n🛍 អ្នកអាចទិញកញ្ចប់ដោយប្រើ Wallet បានឥឡូវនេះ។"
                    : "✅✨ Wallet top-up received!\n\n💵 +{$amount} {$topup->currency}\n👛 Store Wallet: {$balanceText} {$topup->currency}\n\n🛍 You can now buy a package with your wallet balance.",
                [
                    'inline_keyboard' => [
                        [['text' => $km ? '🛍 ទិញកញ្ចប់' : '🛍 Shop now', 'callback_data' => 'store:1']],
                        [['text' => $km ? '👛 កាបូបលុយ' : '👛 My Wallet', 'callback_data' => 'wallet']],
                    ],
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
