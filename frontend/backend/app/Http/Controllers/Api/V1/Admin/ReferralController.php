<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralReward;
use App\Models\ReferralRegistrationReward;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    public function show(ReferralService $referrals): JsonResponse
    {
        $settings = $referrals->settings();

        $earned = ReferralReward::query()
            ->where('status', 'EARNED')
            ->selectRaw('currency, currency_exponent, SUM(referrer_reward_minor) AS referrer_minor, SUM(referred_bonus_minor) AS bonus_minor')
            ->groupBy('currency', 'currency_exponent')
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'exponent' => (int) $row->currency_exponent,
                'referrer_minor' => (string) $row->referrer_minor,
                'bonus_minor' => (string) $row->bonus_minor,
            ])->values();

        $registrationEarned = ReferralRegistrationReward::query()->where('status', 'EARNED')->get()->groupBy('reward_mode')->map(function ($rows, $mode): array {
            return [
                'mode' => (string) $mode,
                'units' => (string) $rows->sum('reward_units'),
                'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
                'exponent' => $mode === 'CREDIT_BALANCE' ? 2 : null,
            ];
        })->values();

        $recentRegistration = ReferralRegistrationReward::query()
            ->with(['referrer:id,name,email', 'referredUser:id,name,email'])
            ->latest('created_at')->limit(50)->get()
            ->map(fn (ReferralRegistrationReward $reward): array => [
                'id' => (string) $reward->id,
                'status' => (string) $reward->status,
                'referrer' => $reward->referrer ? ['id' => (string) $reward->referrer->id, 'name' => $reward->referrer->name, 'email' => $reward->referrer->email] : null,
                'referred_user' => $reward->referredUser ? ['id' => (string) $reward->referredUser->id, 'name' => $reward->referredUser->name, 'email' => $reward->referredUser->email] : null,
                'reward_mode' => (string) $reward->reward_mode,
                'reward_units' => (string) $reward->reward_units,
                'currency' => $reward->currency,
                'currency_exponent' => $reward->currency_exponent,
                'allowed_model_aliases' => $reward->allowed_model_aliases ?? [],
                'awarded_at' => $reward->awarded_at?->toAtomString(),
                'created_at' => $reward->created_at?->toAtomString(),
            ])->values();

        $recent = ReferralReward::query()
            ->with(['referrer:id,name,email', 'referredUser:id,name,email', 'order:id,reference'])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ReferralReward $reward): array => [
                'id' => (string) $reward->id,
                'status' => (string) $reward->status,
                'referrer' => $reward->referrer ? ['id' => (string) $reward->referrer->id, 'name' => $reward->referrer->name, 'email' => $reward->referrer->email] : null,
                'referred_user' => $reward->referredUser ? ['id' => (string) $reward->referredUser->id, 'name' => $reward->referredUser->name, 'email' => $reward->referredUser->email] : null,
                'order_reference' => $reward->order?->reference,
                'order_total_minor' => (string) $reward->order_total_minor,
                'referrer_reward_minor' => (string) $reward->referrer_reward_minor,
                'referred_bonus_minor' => (string) $reward->referred_bonus_minor,
                'currency' => $reward->currency,
                'currency_exponent' => (int) $reward->currency_exponent,
                'awarded_at' => $reward->awarded_at?->toAtomString(),
                'created_at' => $reward->created_at?->toAtomString(),
            ])->values();

        return response()->json(['data' => [
            'settings' => $this->settingsResource($settings),
            'metrics' => [
                'referrers' => User::query()->whereNotNull('referral_code')->whereHas('referrals')->count(),
                'referred_users' => User::query()->whereNotNull('referred_by_user_id')->count(),
                'converted_users' => User::query()->whereNotNull('referred_by_user_id')->whereHas('orders', fn ($query) => $query->where('status', 'FULFILLED'))->count(),
                'rewarded_orders' => ReferralReward::query()->where('status', 'EARNED')->count(),
                'rewarded_registrations' => ReferralRegistrationReward::query()->where('status', 'EARNED')->count(),
                'earned' => $earned,
                'registration_earned' => $registrationEarned,
            ],
            'recent_rewards' => $recent,
            'recent_registration_rewards' => $recentRegistration,
            'available_aliases' => $referrals->registrationRewardAvailableAliases(),
        ]]);
    }

    public function update(Request $request, ReferralService $referrals, AuditService $audit): JsonResponse
    {
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'registration_reward_enabled' => ['required', 'boolean'],
            'registration_reward_mode' => ['required', Rule::in(['CREDIT_BALANCE', 'TOKEN_QUOTA'])],
            'registration_credit_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'registration_token_units' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'registration_reward_model_aliases' => ['nullable', 'array', 'max:100'],
            'registration_reward_model_aliases.*' => ['string', 'max:100'],
            'commission_bps' => ['required', 'integer', 'between:0,5000'],
            'referred_bonus_bps' => ['required', 'integer', 'between:0,5000'],
            'minimum_order_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'cookie_days' => ['required', 'integer', 'between:1,365'],
            'reward_expiry_days' => ['required', 'integer', 'between:0,3650'],
            'commission_all_orders' => ['required', 'boolean'],
            'referred_bonus_first_order_only' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $settings = $referrals->settings();
        $before = $this->settingsResource($settings);
        $reason = (string) $input['reason'];
        unset($input['reason']);
        if (! $settings->registration_reward_enabled && (bool) $input['registration_reward_enabled']) {
            $input['registration_reward_started_at'] = now();
        }
        $settings->forceFill($input)->save();
        $audit->record($request->user(), 'referral.settings_updated', 'referral_setting', '1', $reason, [
            'before' => $before,
            'after' => $this->settingsResource($settings->fresh()),
        ]);

        return response()->json(['data' => $this->settingsResource($settings->fresh())]);
    }

    private function settingsResource(ReferralSetting $settings): array
    {
        return [
            'enabled' => (bool) $settings->enabled,
            'registration_reward_enabled' => (bool) $settings->registration_reward_enabled,
            'registration_reward_mode' => (string) $settings->registration_reward_mode,
            'registration_credit_minor' => (string) $settings->registration_credit_minor,
            'registration_token_units' => (string) $settings->registration_token_units,
            'registration_reward_model_aliases' => array_values($settings->registration_reward_model_aliases ?? []),
            'commission_bps' => (int) $settings->commission_bps,
            'referred_bonus_bps' => (int) $settings->referred_bonus_bps,
            'minimum_order_minor' => (string) $settings->minimum_order_minor,
            'cookie_days' => (int) $settings->cookie_days,
            'reward_expiry_days' => (int) $settings->reward_expiry_days,
            'commission_all_orders' => (bool) $settings->commission_all_orders,
            'referred_bonus_first_order_only' => (bool) $settings->referred_bonus_first_order_only,
        ];
    }
}
