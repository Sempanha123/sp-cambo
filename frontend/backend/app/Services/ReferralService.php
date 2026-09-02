<?php

namespace App\Services;

use App\Models\ModelAlias;
use App\Models\Order;
use App\Models\ReferralReward;
use App\Models\ReferralRegistrationReward;
use App\Models\ReferralSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function settings(): ReferralSetting
    {
        return ReferralSetting::query()->firstOrCreate(['id' => 1], [
            'enabled' => true,
            'registration_reward_enabled' => true,
            'registration_reward_started_at' => now(),
            'registration_reward_mode' => 'CREDIT_BALANCE',
            'registration_credit_minor' => 25,
            'registration_token_units' => 25000,
            'registration_reward_model_aliases' => null,
            'commission_bps' => 1000,
            'referred_bonus_bps' => 500,
            'minimum_order_minor' => 100,
            'cookie_days' => 30,
            'reward_expiry_days' => 90,
            'commission_all_orders' => true,
            'referred_bonus_first_order_only' => true,
        ]);
    }

    public function ensureCode(User $user): string
    {
        if (is_string($user->referral_code) && $user->referral_code !== '') {
            return $user->referral_code;
        }

        return DB::transaction(function () use ($user): string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (is_string($locked->referral_code) && $locked->referral_code !== '') {
                return $locked->referral_code;
            }

            do {
                $code = 'SP'.Str::upper(Str::random(10));
            } while (User::query()->where('referral_code', $code)->exists());

            $locked->forceFill(['referral_code' => $code])->saveQuietly();
            $user->referral_code = $code;

            return $code;
        });
    }

    public function claim(User $user, string $code): User
    {
        $settings = $this->settings();
        if (! $settings->enabled) {
            throw ValidationException::withMessages(['referral_code' => ['The referral program is currently paused.']]);
        }

        $code = Str::upper(trim($code));

        $claimed = DB::transaction(function () use ($user, $code): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->referred_by_user_id !== null) {
                return $locked;
            }

            if (Order::query()->where('user_id', $locked->id)->where('status', 'FULFILLED')->exists()) {
                throw ValidationException::withMessages(['referral_code' => ['A referral can only be attached before your first completed purchase.']]);
            }

            $referrer = User::query()->where('referral_code', $code)->first();
            if (! $referrer) {
                throw ValidationException::withMessages(['referral_code' => ['This referral code is not valid.']]);
            }

            if ((int) $referrer->id === (int) $locked->id) {
                throw ValidationException::withMessages(['referral_code' => ['You cannot refer yourself.']]);
            }

            if ((int) ($referrer->referred_by_user_id ?? 0) === (int) $locked->id) {
                throw ValidationException::withMessages(['referral_code' => ['This referral would create a referral loop.']]);
            }

            $locked->forceFill([
                'referred_by_user_id' => $referrer->id,
                'referred_at' => now(),
            ])->saveQuietly();

            return $locked->fresh();
        });

        // Registration bounties are intentionally immediate: once a valid referral
        // is attached to a successfully-created account, the inviter receives the
        // configured account reward. The unique referred_user_id plus entitlement
        // idempotency key make retries safe.
        try {
            $this->rewardRegistration($claimed);
        } catch (\Throwable $exception) {
            // Referral accounting must never block a successful registration.
            // The scheduled reconciliation command retries idempotently.
            Log::error('Immediate referral registration reward failed.', [
                'referred_user_id' => $claimed->id,
                'referrer_user_id' => $claimed->referred_by_user_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $claimed->fresh();
    }

    /**
     * Aliases an operator can use to scope signup rewards.
     *
     * @return array<int, string>
     */
    public function registrationRewardAvailableAliases(): array
    {
        return $this->accountCreditAliases();
    }

    public function rewardRegistration(User $referred, bool $ignoreStartBoundary = false): ?ReferralRegistrationReward
    {
        $settings = $this->settings();
        if (! $settings->enabled || ! $settings->registration_reward_enabled || $referred->referred_by_user_id === null) {
            return null;
        }
        if (! $ignoreStartBoundary && $settings->registration_reward_started_at && (! $referred->referred_at || $referred->referred_at->lt($settings->registration_reward_started_at))) {
            return null;
        }

        $mode = in_array($settings->registration_reward_mode, ['CREDIT_BALANCE', 'TOKEN_QUOTA'], true)
            ? $settings->registration_reward_mode
            : 'CREDIT_BALANCE';
        $units = $mode === 'TOKEN_QUOTA'
            ? (int) $settings->registration_token_units
            : (int) $settings->registration_credit_minor;
        if ($units <= 0) {
            return null;
        }

        $aliases = $this->registrationRewardAliases($settings);
        if ($aliases === []) {
            // A referral credit is an accounting promise and must not disappear just
            // because model routing is temporarily unavailable. Store an empty model
            // scope for now; reconciliation repairs the scope as soon as a public
            // alias is configured. Until then the credit exists but cannot be spent.
            Log::warning('Referral registration reward is being granted without an available model alias.', [
                'referred_user_id' => $referred->id,
                'referrer_user_id' => $referred->referred_by_user_id,
            ]);
        }

        return DB::transaction(function () use ($referred, $settings, $mode, $units, $aliases): ?ReferralRegistrationReward {
            $existing = ReferralRegistrationReward::query()->where('referred_user_id', $referred->id)->lockForUpdate()->first();
            if ($existing) {
                $this->repairRegistrationRewardAliasScope($existing, $aliases);

                return $existing->fresh();
            }

            $buyer = User::query()->lockForUpdate()->find($referred->id);
            $referrer = $buyer?->referred_by_user_id ? User::query()->lockForUpdate()->find($buyer->referred_by_user_id) : null;
            if (! $buyer || ! $referrer || (int) $referrer->id === (int) $buyer->id) {
                return null;
            }

            $reward = ReferralRegistrationReward::query()->create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'status' => 'EARNED',
                'reward_mode' => $mode,
                'reward_units' => $units,
                'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
                'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 2 : null,
                'allowed_model_aliases' => $aliases,
                'metadata' => [
                    'trigger' => 'successful_referral_registration',
                    'model_scope' => $settings->registration_reward_model_aliases ? 'CONFIGURED' : 'ALL_PUBLIC',
                    'awaiting_model_aliases' => $aliases === [],
                ],
            ]);

            $expiresAt = (int) $settings->reward_expiry_days > 0 ? now()->addDays((int) $settings->reward_expiry_days) : null;
            $lot = $this->entitlements->grant($referrer, [
                'source_type' => 'REFERRAL',
                'source_id' => $reward->id,
                'package_name' => 'Referral registration reward',
                'family_label' => $mode === 'TOKEN_QUOTA' ? 'Referral tokens' : 'Referral credit',
                'billing_mode' => $mode,
                'original_units' => $units,
                'unit_label' => $mode === 'TOKEN_QUOTA' ? 'tokens' : 'USD credit',
                'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
                'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 2 : null,
                'allowed_model_aliases' => $aliases,
                'billing_snapshot' => [
                    'referral_registration_reward_id' => $reward->id,
                    'role' => 'referrer',
                    'trigger' => 'registration',
                    'model_scope' => $settings->registration_reward_model_aliases ? 'CONFIGURED' : 'ALL_PUBLIC',
                    'awaiting_model_aliases' => $aliases === [],
                ],
                'activated_at' => now(),
                'expires_at' => $expiresAt,
                'access_scope' => 'ACCOUNT',
                'bound_api_key_id' => null,
                'fulfillment_claim_id' => null,
                'reason' => 'Referral reward granted when an invited customer registered successfully.',
            ], "referral-registration:{$buyer->id}:referrer");

            $reward->entitlement_lot_id = $lot->id;
            $reward->awarded_at = now();
            $reward->save();

            return $reward->fresh();
        });
    }

    public function reconcileRegistrations(int $batch = 100, bool $includeBeforeStart = false): array
    {
        $settings = $this->settings();
        $batch = max(1, min(500, $batch));
        $aliases = $this->registrationRewardAliases($settings);
        $repaired = $this->repairAwaitingRegistrationRewardAliasScopes($aliases, $batch);

        // Disabling future referral rewards must not strand credit that was already
        // earned while no model alias existed. Alias-scope repair therefore runs
        // even when new reward issuance is paused.
        if (! $settings->enabled || ! $settings->registration_reward_enabled) {
            return ['checked' => 0, 'rewarded' => 0, 'skipped' => 0, 'failed' => 0, 'repaired' => $repaired];
        }

        $users = User::query()->whereNotNull('referred_by_user_id')
            ->when(
                ! $includeBeforeStart && $settings->registration_reward_started_at,
                fn ($query) => $query->where('referred_at', '>=', $settings->registration_reward_started_at),
            )
            ->whereNotIn('id', ReferralRegistrationReward::query()->select('referred_user_id'))
            ->oldest('referred_at')
            ->limit($batch)
            ->get();

        $rewarded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $this->rewardRegistration($user, $includeBeforeStart) ? $rewarded++ : $skipped++;
            } catch (\Throwable $exception) {
                $failed++;
                Log::error('Referral registration reconciliation failed for a referred account.', [
                    'referred_user_id' => $user->id,
                    'referrer_user_id' => $user->referred_by_user_id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'checked' => $users->count(),
            'rewarded' => $rewarded,
            'skipped' => $skipped,
            'failed' => $failed,
            'repaired' => $repaired,
        ];
    }

    public function rewardFulfilledOrder(Order $order): ?ReferralReward
    {
        $settings = $this->settings();
        if (! $settings->enabled || $order->status !== 'FULFILLED') {
            return null;
        }

        $buyer = $order->user()->first();
        if (! $buyer || $buyer->referred_by_user_id === null || (int) $order->total_minor < (int) $settings->minimum_order_minor) {
            return null;
        }

        if (ReferralReward::query()->where('order_id', $order->id)->exists()) {
            return ReferralReward::query()->where('order_id', $order->id)->first();
        }

        $aliases = $this->accountCreditAliases();
        if ($aliases === []) {
            Log::warning('Referral purchase reward is waiting because no public model alias is available.', [
                'order_id' => $order->id,
                'referred_user_id' => $buyer->id,
                'referrer_user_id' => $buyer->referred_by_user_id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($order, $buyer, $settings, $aliases): ?ReferralReward {
            $existing = ReferralReward::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $referrer = User::query()->lockForUpdate()->find($buyer->referred_by_user_id);
            if (! $referrer || (int) $referrer->id === (int) $buyer->id) {
                return null;
            }

            $priorRewardCount = ReferralReward::query()->where('referred_user_id', $buyer->id)->where('status', 'EARNED')->count();
            $commissionAllowed = $settings->commission_all_orders || $priorRewardCount === 0;
            $bonusAllowed = ! $settings->referred_bonus_first_order_only || $priorRewardCount === 0;

            $commission = $commissionAllowed ? $this->basisPointAmount((int) $order->total_minor, (int) $settings->commission_bps) : 0;
            $bonus = $bonusAllowed ? $this->basisPointAmount((int) $order->total_minor, (int) $settings->referred_bonus_bps) : 0;

            $reward = ReferralReward::query()->create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'order_id' => $order->id,
                'status' => ($commission > 0 || $bonus > 0) ? 'EARNED' : 'INELIGIBLE',
                'order_total_minor' => (int) $order->total_minor,
                'referrer_reward_minor' => $commission,
                'referred_bonus_minor' => $bonus,
                'currency' => $order->currency,
                'currency_exponent' => (int) $order->currency_exponent,
                'metadata' => [
                    'commission_bps' => (int) $settings->commission_bps,
                    'referred_bonus_bps' => (int) $settings->referred_bonus_bps,
                    'minimum_order_minor' => (int) $settings->minimum_order_minor,
                ],
            ]);

            $expiresAt = (int) $settings->reward_expiry_days > 0 ? now()->addDays((int) $settings->reward_expiry_days) : null;

            if ($commission > 0) {
                $lot = $this->entitlements->grant($referrer, [
                    'source_type' => 'REFERRAL',
                    'source_id' => $reward->id,
                    'package_name' => 'Referral reward',
                    'family_label' => 'Referral credit',
                    'billing_mode' => 'CREDIT_BALANCE',
                    'original_units' => $commission,
                    'unit_label' => $order->currency.' credit',
                    'currency' => $order->currency,
                    'currency_exponent' => (int) $order->currency_exponent,
                    'allowed_model_aliases' => $aliases,
                    'billing_snapshot' => ['referral_reward_id' => $reward->id, 'order_id' => $order->id, 'role' => 'referrer'],
                    'activated_at' => now(),
                    'expires_at' => $expiresAt,
                    'access_scope' => 'ACCOUNT',
                    'bound_api_key_id' => null,
                    'fulfillment_claim_id' => null,
                    'reason' => 'Referral commission from a fulfilled order.',
                ], "referral:{$reward->id}:referrer");
                $reward->referrer_entitlement_lot_id = $lot->id;
            }

            if ($bonus > 0) {
                $lot = $this->entitlements->grant($buyer, [
                    'source_type' => 'REFERRAL',
                    'source_id' => $reward->id,
                    'package_name' => 'Referral welcome bonus',
                    'family_label' => 'Referral credit',
                    'billing_mode' => 'CREDIT_BALANCE',
                    'original_units' => $bonus,
                    'unit_label' => $order->currency.' credit',
                    'currency' => $order->currency,
                    'currency_exponent' => (int) $order->currency_exponent,
                    'allowed_model_aliases' => $aliases,
                    'billing_snapshot' => ['referral_reward_id' => $reward->id, 'order_id' => $order->id, 'role' => 'referred_customer'],
                    'activated_at' => now(),
                    'expires_at' => $expiresAt,
                    'access_scope' => 'ACCOUNT',
                    'bound_api_key_id' => null,
                    'fulfillment_claim_id' => null,
                    'reason' => 'Referral welcome bonus from the first eligible fulfilled order.',
                ], "referral:{$reward->id}:referred");
                $reward->referred_entitlement_lot_id = $lot->id;
            }

            $reward->awarded_at = now();
            $reward->save();

            return $reward->fresh();
        });
    }

    public function reconcileFulfilled(int $batch = 100): array
    {
        $settings = $this->settings();
        if (! $settings->enabled) {
            return ['checked' => 0, 'rewarded' => 0, 'skipped' => 0];
        }

        $batch = max(1, min(500, $batch));
        $orders = Order::query()
            ->where('status', 'FULFILLED')
            ->where('total_minor', '>=', (int) $settings->minimum_order_minor)
            ->whereHas('user', fn ($query) => $query->whereNotNull('referred_by_user_id'))
            ->whereNotIn('id', ReferralReward::query()->select('order_id'))
            ->oldest('fulfilled_at')
            ->limit($batch)
            ->get();

        $rewarded = 0;
        $skipped = 0;
        foreach ($orders as $order) {
            $reward = $this->rewardFulfilledOrder($order);
            $reward ? $rewarded++ : $skipped++;
        }

        return ['checked' => $orders->count(), 'rewarded' => $rewarded, 'skipped' => $skipped];
    }

    /**
     * Resolve the model scope for an instant signup reward.
     *
     * Explicit admin selections are preserved even while a route is temporarily
     * unavailable. With an empty selection, prefer strictly published aliases and
     * then fall back to configured customer-facing aliases. This keeps reward
     * issuance independent from transient provider readiness without bypassing the
     * normal inference publication checks.
     *
     * @return array<int, string>
     */
    private function registrationRewardAliases(ReferralSetting $settings): array
    {
        $configured = collect($settings->registration_reward_model_aliases ?? [])
            ->filter(fn ($alias) => is_string($alias) && trim($alias) !== '')
            ->map(fn ($alias) => trim($alias))
            ->unique()
            ->values();

        if ($configured->isNotEmpty()) {
            return $configured->all();
        }

        return $this->accountCreditAliases();
    }

    /**
     * Resolve aliases suitable for account-scoped referral credit.
     *
     * Strictly published aliases are preferred. If provider readiness or resale
     * verification is temporarily incomplete, retain the public customer-facing
     * alias scope so the accounting reward is still issued and becomes spendable
     * automatically when routing is ready.
     *
     * @return array<int, string>
     */
    private function accountCreditAliases(): array
    {
        $published = ModelAlias::query()
            ->published()
            ->orderBy('public_alias')
            ->pluck('public_alias')
            ->all();

        if ($published !== []) {
            return $published;
        }

        return ModelAlias::query()
            ->where('enabled', true)
            ->where('customer_visible', true)
            ->whereIn('status', ['active', 'beta'])
            ->orderBy('public_alias')
            ->pluck('public_alias')
            ->all();
    }

    /**
     * Repair a reward that was issued while no model alias existed yet.
     *
     * @param array<int, string> $aliases
     */
    private function repairRegistrationRewardAliasScope(ReferralRegistrationReward $reward, array $aliases): bool
    {
        if ($aliases === [] || ($reward->allowed_model_aliases ?? []) !== []) {
            return false;
        }

        $metadata = is_array($reward->metadata) ? $reward->metadata : [];
        $metadata['awaiting_model_aliases'] = false;
        $metadata['model_aliases_repaired_at'] = now()->toAtomString();

        $reward->forceFill([
            'allowed_model_aliases' => $aliases,
            'metadata' => $metadata,
        ])->save();

        $lot = $reward->entitlementLot()->lockForUpdate()->first();
        if ($lot && ($lot->allowed_model_aliases ?? []) === []) {
            $snapshot = is_array($lot->billing_snapshot) ? $lot->billing_snapshot : [];
            $snapshot['awaiting_model_aliases'] = false;
            $snapshot['model_aliases_repaired_at'] = now()->toAtomString();
            $lot->forceFill([
                'allowed_model_aliases' => $aliases,
                'billing_snapshot' => $snapshot,
            ])->save();
        }

        return true;
    }

    /**
     * Repair previously-issued signup rewards that were waiting for a model scope.
     *
     * @param array<int, string> $aliases
     */
    private function repairAwaitingRegistrationRewardAliasScopes(array $aliases, int $batch): int
    {
        if ($aliases === []) {
            return 0;
        }

        $rewards = ReferralRegistrationReward::query()
            ->where('status', 'EARNED')
            ->whereNotNull('entitlement_lot_id')
            ->whereJsonLength('allowed_model_aliases', 0)
            ->oldest('created_at')
            ->limit($batch)
            ->get();

        $repaired = 0;
        foreach ($rewards as $reward) {
            DB::transaction(function () use ($reward, $aliases, &$repaired): void {
                $locked = ReferralRegistrationReward::query()->lockForUpdate()->find($reward->id);
                if ($locked && $this->repairRegistrationRewardAliasScope($locked, $aliases)) {
                    $repaired++;
                }
            });
        }

        return $repaired;
    }

    private function basisPointAmount(int $minor, int $bps): int
    {
        if ($minor <= 0 || $bps <= 0) {
            return 0;
        }

        return intdiv(($minor * $bps) + 5000, 10000);
    }
}
