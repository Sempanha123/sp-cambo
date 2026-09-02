<?php

namespace App\Services;

use App\Exceptions\RedeemCodeException;

use App\Models\EntitlementLot;
use App\Models\RedeemCode;
use App\Models\RedeemCodeRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RedeemCodeService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @return array{redeem_code:RedeemCode,secret:string} */
    public function issue(User $actor, array $attributes): array
    {
        $secret = 'SPC-FREE-'.Str::upper(Str::random(20));
        $code = RedeemCode::query()->create($attributes + [
            'code_digest' => $this->digest($secret),
            'prefix' => 'SPC-FREE-',
            'last_four' => substr($secret, -4),
            'created_by_user_id' => $actor->id,
        ]);

        return ['redeem_code' => $code, 'secret' => $secret];
    }

    public function redeem(User $user, string $secret, string $idempotencyKey): EntitlementLot
    {
        return DB::transaction(function () use ($user, $secret, $idempotencyKey): EntitlementLot {
            $prior = RedeemCodeRedemption::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior) {
                if ((int) $prior->user_id !== (int) $user->id) {
                    throw new RedeemCodeException('idempotency_conflict', 'This redemption key was already used for another account.', 409);
                }
                return EntitlementLot::query()->findOrFail($prior->entitlement_lot_id);
            }

            $code = RedeemCode::query()->where('code_digest', $this->digest($secret))->lockForUpdate()->first();
            if (! $code || ! $code->enabled || $code->starts_at?->isFuture() || $code->ends_at?->isPast()) {
                throw new RedeemCodeException('redeem_code_unavailable', 'This redeem code is invalid or no longer available.', 422);
            }

            if ($code->max_redemptions !== null && $code->redemptions()->count() >= (int) $code->max_redemptions) {
                throw new RedeemCodeException('redeem_code_exhausted', 'This redeem code has reached its redemption limit.', 422);
            }
            if ($code->redemptions()->where('user_id', $user->id)->count() >= (int) $code->per_user_limit) {
                throw new RedeemCodeException('redeem_code_already_used', 'You have already used this redeem code.', 409);
            }

            $lot = $this->entitlements->grant($user, [
                'source_type' => 'REDEEM_CODE',
                'source_id' => $code->id,
                'package_name' => $code->label,
                'family_label' => 'Redeem code',
                'billing_mode' => $code->billing_mode,
                'original_units' => (int) $code->units,
                'unit_label' => $code->billing_mode === 'CREDIT_BALANCE' ? 'microcredits' : 'tokens',
                'currency' => $code->billing_mode === 'CREDIT_BALANCE' ? 'USD' : null,
                'currency_exponent' => $code->billing_mode === 'CREDIT_BALANCE' ? 6 : null,
                'allowed_model_aliases' => $code->allowed_model_aliases,
                'billing_snapshot' => ['billing_rules' => $code->billing_rules ?? []],
                'activated_at' => now(),
                'expires_at' => now()->addSeconds((int) $code->duration_seconds),
            ], "redeem:{$code->id}:{$user->id}:{$idempotencyKey}");

            RedeemCodeRedemption::query()->create([
                'redeem_code_id' => $code->id,
                'user_id' => $user->id,
                'entitlement_lot_id' => $lot->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $lot;
        });
    }

    public function digest(string $secret): string
    {
        $lookupSecret = (string) (config('services.spcambo.redeem_code_lookup_secret') ?: config('app.key'));
        if ($lookupSecret === '') {
            throw new RuntimeException('Redeem code lookup secret is not configured.');
        }
        return hash_hmac('sha256', mb_strtoupper(trim($secret)), $lookupSecret);
    }
}
