<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lots = $this->user($request)->entitlementLots()->with('boundApiKey:id,label,prefix,last_four')->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('created_at')->get();

        return response()->json(['data' => $lots->map(fn (EntitlementLot $lot) => $this->lot($lot))->values()]);
    }

    public function balance(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $lots = $user->entitlementLots()
            ->where('status', 'ACTIVE')
            ->where(function ($access): void {
                $access->whereNull('access_scope')->orWhere('access_scope', '!=', 'UNASSIGNED');
            })
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        $tokens = $lots->where('billing_mode', 'TOKEN_QUOTA');
        $credits = $lots->where('billing_mode', 'CREDIT_BALANCE');
        $currency = $credits->first()?->currency ?? 'USD';
        $exponent = $credits->first()?->currency_exponent ?? 6;

        return response()->json(['data' => ['token_quota' => ['remaining_units' => (string) $tokens->sum('remaining_units'), 'reserved_units' => (string) $tokens->sum('reserved_units'), 'original_units' => (string) $tokens->sum('original_units')], 'credit_balance' => ['remaining' => $this->money($credits->sum('remaining_units'), $currency, $exponent), 'reserved' => $this->money($credits->sum('reserved_units'), $currency, $exponent)], 'next_expires_at' => $lots->whereNotNull('expires_at')->min('expires_at')?->toAtomString(), 'active_lot_count' => $lots->count(), 'version' => (int) CreditLedger::query()->where('user_id', $user->id)->max('id')]]);
    }

    private function lot(EntitlementLot $lot): array
    {
        $remainingAmount = $lot->billing_mode === 'CREDIT_BALANCE' ? $this->money($lot->remaining_units, $lot->currency ?? 'USD', $lot->currency_exponent ?? 6) : null;

        return ['id' => $lot->id, 'billing_mode' => $lot->billing_mode, 'package_name' => $lot->package_name, 'family_label' => $lot->family_label, 'original_units' => (string) $lot->original_units, 'remaining_units' => (string) $lot->remaining_units, 'reserved_units' => (string) $lot->reserved_units, 'unit_label' => $lot->unit_label, 'remaining_amount' => $remainingAmount, 'activated_at' => $lot->activated_at?->toAtomString(), 'expires_at' => $lot->expires_at?->toAtomString(), 'allowed_model_aliases' => $lot->allowed_model_aliases, 'status' => $lot->status, 'source' => $lot->source_type, 'access_scope' => $lot->access_scope ?? 'ACCOUNT', 'fulfillment_claim_id' => $lot->fulfillment_claim_id, 'bound_api_key' => $lot->boundApiKey ? ['id' => (string) $lot->boundApiKey->id, 'label' => $lot->boundApiKey->label, 'masked_key' => $lot->boundApiKey->prefix.'…'.$lot->boundApiKey->last_four] : null];
    }

    private function money(int|string $minor, string $currency, int $exponent): array
    {
        return ['minor' => (string) $minor, 'currency' => $currency, 'exponent' => $exponent];
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
