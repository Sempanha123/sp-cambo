<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReferralReward;
use App\Models\ReferralRegistrationReward;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    public function show(Request $request, ReferralService $referrals): JsonResponse
    {
        $user = $request->user();
        $settings = $referrals->settings();
        $code = $referrals->ensureCode($user);

        $invites = User::query()
            ->where('referred_by_user_id', $user->id)
            ->withCount(['orders as fulfilled_orders_count' => fn ($query) => $query->where('status', 'FULFILLED')])
            ->latest('referred_at')
            ->limit(20)
            ->get(['id', 'name', 'created_at', 'referred_at'])
            ->map(fn (User $invite): array => [
                'id' => (string) $invite->id,
                'name' => (string) $invite->name,
                'joined_at' => $invite->created_at?->toAtomString(),
                'referred_at' => $invite->referred_at?->toAtomString(),
                'converted' => (int) $invite->fulfilled_orders_count > 0,
                'fulfilled_orders' => (int) $invite->fulfilled_orders_count,
                'registration_rewarded' => ReferralRegistrationReward::query()->where('referred_user_id', $invite->id)->where('status', 'EARNED')->exists(),
            ])->values();

        $rewards = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->with(['referredUser:id,name', 'order:id,reference'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ReferralReward $reward): array => [
                'id' => (string) $reward->id,
                'status' => (string) $reward->status,
                'referred_user' => $reward->referredUser ? ['id' => (string) $reward->referredUser->id, 'name' => (string) $reward->referredUser->name] : null,
                'order_reference' => $reward->order?->reference,
                'order_total' => $this->money((int) $reward->order_total_minor, $reward->currency, (int) $reward->currency_exponent),
                'reward' => $this->money((int) $reward->referrer_reward_minor, $reward->currency, (int) $reward->currency_exponent),
                'awarded_at' => $reward->awarded_at?->toAtomString(),
                'created_at' => $reward->created_at?->toAtomString(),
            ])->values();

        $registrationRewards = ReferralRegistrationReward::query()
            ->where('referrer_user_id', $user->id)
            ->with('referredUser:id,name')
            ->latest('created_at')->limit(20)->get()
            ->map(fn (ReferralRegistrationReward $reward): array => [
                'id' => (string) $reward->id,
                'status' => (string) $reward->status,
                'referred_user' => $reward->referredUser ? ['id' => (string) $reward->referredUser->id, 'name' => (string) $reward->referredUser->name] : null,
                'reward_mode' => (string) $reward->reward_mode,
                'reward_units' => (string) $reward->reward_units,
                'currency' => $reward->currency,
                'currency_exponent' => $reward->currency_exponent,
                'awarded_at' => $reward->awarded_at?->toAtomString(),
            ])->values();

        $earnedTotals = [];

        $purchaseEarned = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', 'EARNED')
            ->selectRaw('currency, currency_exponent, SUM(referrer_reward_minor) AS total_minor')
            ->groupBy('currency', 'currency_exponent')
            ->get();

        foreach ($purchaseEarned as $row) {
            $currency = (string) $row->currency;
            $exponent = (int) $row->currency_exponent;
            $key = $currency.':'.$exponent;
            $earnedTotals[$key] ??= ['minor' => 0, 'currency' => $currency, 'exponent' => $exponent];
            $earnedTotals[$key]['minor'] += (int) $row->total_minor;
        }

        $registrationEarned = ReferralRegistrationReward::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', 'EARNED')
            ->where('reward_mode', 'CREDIT_BALANCE')
            ->selectRaw('currency, currency_exponent, SUM(reward_units) AS total_minor')
            ->groupBy('currency', 'currency_exponent')
            ->get();

        foreach ($registrationEarned as $row) {
            $currency = (string) ($row->currency ?: 'USD');
            $exponent = (int) ($row->currency_exponent ?? 2);
            $key = $currency.':'.$exponent;
            $earnedTotals[$key] ??= ['minor' => 0, 'currency' => $currency, 'exponent' => $exponent];
            $earnedTotals[$key]['minor'] += (int) $row->total_minor;
        }

        $earned = collect($earnedTotals)
            ->map(fn (array $row): array => $this->money($row['minor'], $row['currency'], $row['exponent']))
            ->values();

        return response()->json(['data' => [
            'enabled' => (bool) $settings->enabled,
            'code' => $code,
            'share_url' => rtrim((string) config('app.frontend_url'), '/').'/r/'.$code,
            'cookie_days' => (int) $settings->cookie_days,
            'commission_bps' => (int) $settings->commission_bps,
            'registration_reward_enabled' => (bool) $settings->registration_reward_enabled,
            'registration_reward_mode' => (string) $settings->registration_reward_mode,
            'registration_credit_minor' => (string) $settings->registration_credit_minor,
            'registration_token_units' => (string) $settings->registration_token_units,
            'referred_bonus_bps' => (int) $settings->referred_bonus_bps,
            'reward_expiry_days' => (int) $settings->reward_expiry_days,
            'referred_by' => $user->referrer ? [
                'name' => (string) $user->referrer->name,
                'referred_at' => $user->referred_at?->toAtomString(),
            ] : null,
            'metrics' => [
                'invited' => User::query()->where('referred_by_user_id', $user->id)->count(),
                'converted' => User::query()->where('referred_by_user_id', $user->id)->whereHas('orders', fn ($query) => $query->where('status', 'FULFILLED'))->count(),
                'rewarded_orders' => ReferralReward::query()->where('referrer_user_id', $user->id)->where('status', 'EARNED')->count(),
                'rewarded_registrations' => ReferralRegistrationReward::query()->where('referrer_user_id', $user->id)->where('status', 'EARNED')->count(),
                'earned' => $earned,
            ],
            'invites' => $invites,
            'rewards' => $rewards,
            'registration_rewards' => $registrationRewards,
        ]]);
    }

    public function claim(Request $request, ReferralService $referrals): JsonResponse
    {
        $input = $request->validate([
            'referral_code' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $user = $referrals->claim($request->user(), Str::upper($input['referral_code']));

        return response()->json(['data' => [
            'claimed' => true,
            'registration_rewarded_referrer' => ReferralRegistrationReward::query()->where('referred_user_id', $user->id)->where('status', 'EARNED')->exists(),
            'referred_at' => $user->referred_at?->toAtomString(),
        ]]);
    }

    public function resolve(string $code, ReferralService $referrals): JsonResponse
    {
        $settings = $referrals->settings();
        $normalized = Str::upper(trim($code));
        $valid = $settings->enabled && User::query()->where('referral_code', $normalized)->exists();

        return response()->json(['data' => [
            'valid' => $valid,
            'code' => $valid ? $normalized : null,
            'cookie_days' => (int) $settings->cookie_days,
        ]]);
    }

    private function money(int $minor, string $currency, int $exponent): array
    {
        return ['minor' => (string) $minor, 'currency' => $currency, 'exponent' => $exponent];
    }
}
