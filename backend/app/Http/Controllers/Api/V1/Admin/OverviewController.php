<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntitlementLot;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $orders = Order::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $payments = PaymentAttempt::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $revenueByCurrency = Order::query()
            ->where('status', 'FULFILLED')
            ->selectRaw('currency, currency_exponent, SUM(total_minor) as minor')
            ->groupBy('currency', 'currency_exponent')
            ->orderBy('currency')
            ->orderBy('currency_exponent')
            ->get()
            ->map(fn ($row): array => ['minor' => (string) $row->minor, 'currency' => $row->currency, 'exponent' => (int) $row->currency_exponent]);
        $singleRevenue = $revenueByCurrency->count() === 1 ? $revenueByCurrency->first() : null;

        return response()->json(['data' => ['updated_at' => now()->toAtomString(), 'users' => ['total' => User::query()->count(), 'active' => User::query()->where('status', 'ACTIVE')->count()], 'orders' => ['total' => array_sum($orders->map(fn ($value) => (int) $value)->all()), 'by_status' => $orders->map(fn ($value) => (int) $value)], 'payments' => ['total' => array_sum($payments->map(fn ($value) => (int) $value)->all()), 'by_status' => $payments->map(fn ($value) => (int) $value)], 'fulfilled_revenue' => ['minor' => $singleRevenue['minor'] ?? '0', 'currency' => $singleRevenue['currency'] ?? null, 'exponent' => $singleRevenue['exponent'] ?? null, 'mixed_currency' => $revenueByCurrency->count() > 1, 'by_currency' => $revenueByCurrency], 'entitlements' => ['active_lots' => EntitlementLot::query()->where('status', 'ACTIVE')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count()], 'reservations' => ['active' => Reservation::query()->where('status', 'ACTIVE')->count()], 'ledger_entries' => DB::table('credit_ledger')->count()]]);
    }
}
