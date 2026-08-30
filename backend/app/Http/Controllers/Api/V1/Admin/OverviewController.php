<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntitlementLot;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Reservation;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $orders = Order::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $payments = PaymentAttempt::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $revenueByCurrency = Order::query()
            ->where('status', 'FULFILLED')
            ->selectRaw('currency, currency_exponent, SUM(total_minor) as minor')
            ->groupBy('currency', 'currency_exponent')
            ->orderBy('currency')
            ->orderBy('currency_exponent')
            ->get()
            ->map(fn ($row): array => [
                'minor' => (string) $row->minor,
                'currency' => $row->currency,
                'exponent' => (int) $row->currency_exponent,
            ]);

        $singleRevenue = $revenueByCurrency->count() === 1 ? $revenueByCurrency->first() : null;
        $privateFinance = $this->privateFinance($revenueByCurrency->all());

        return response()->json(['data' => [
            'updated_at' => now()->toAtomString(),
            'users' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('status', 'ACTIVE')->count(),
            ],
            'orders' => [
                'total' => array_sum($orders->map(fn ($value) => (int) $value)->all()),
                'by_status' => $orders->map(fn ($value) => (int) $value),
            ],
            'payments' => [
                'total' => array_sum($payments->map(fn ($value) => (int) $value)->all()),
                'by_status' => $payments->map(fn ($value) => (int) $value),
            ],
            'fulfilled_revenue' => [
                'minor' => $singleRevenue['minor'] ?? '0',
                'currency' => $singleRevenue['currency'] ?? null,
                'exponent' => $singleRevenue['exponent'] ?? null,
                'mixed_currency' => $revenueByCurrency->count() > 1,
                'by_currency' => $revenueByCurrency,
            ],
            // ADMIN ONLY. This endpoint is protected by permission:admin.view.
            // Customer/catalog/usage endpoints intentionally never return
            // upstream cost, gross position, or margin.
            'private_finance' => $privateFinance,
            'entitlements' => [
                'active_lots' => EntitlementLot::query()
                    ->where('status', 'ACTIVE')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
            ],
            'reservations' => [
                'active' => Reservation::query()->where('status', 'ACTIVE')->count(),
            ],
            'ledger_entries' => DB::table('credit_ledger')->count(),
        ]]);
    }

    /**
     * Private operator economics from actual settled usage.
     *
     * `gross_position_minor` is fulfilled sales revenue minus upstream cost
     * incurred so far. It is not called final profit because prepaid token/credit
     * packages may still carry future usage obligations.
     *
     * @param  list<array{minor:string,currency:string,exponent:int}>  $revenueRows
     * @return array<string,mixed>
     */
    private function privateFinance(array $revenueRows): array
    {
        $usageRows = UsageRecord::query()
            ->whereNotNull('currency')
            ->whereNotNull('currency_exponent')
            ->selectRaw(<<<'SQL'
                currency,
                currency_exponent,
                COUNT(*) as settled_records,
                SUM(CASE WHEN upstream_cost_minor IS NULL THEN 1 ELSE 0 END) as unknown_cost_records,
                SUM(CASE WHEN upstream_cost_minor IS NOT NULL THEN 1 ELSE 0 END) as costed_records,
                SUM(CASE WHEN credit_charge_minor IS NULL THEN 1 ELSE 0 END) as token_quota_records,
                SUM(COALESCE(credit_charge_minor, 0)) as customer_usage_value_minor,
                SUM(COALESCE(upstream_cost_minor, 0)) as upstream_cost_minor,
                SUM(CASE
                    WHEN credit_charge_minor IS NOT NULL AND upstream_cost_minor IS NOT NULL
                    THEN CAST(credit_charge_minor AS SIGNED) - CAST(upstream_cost_minor AS SIGNED)
                    ELSE 0
                END) as known_credit_profit_minor,
                SUM(CASE
                    WHEN credit_charge_minor IS NOT NULL AND upstream_cost_minor IS NOT NULL
                    THEN credit_charge_minor
                    ELSE 0
                END) as known_credit_revenue_minor
            SQL)
            ->groupBy('currency', 'currency_exponent')
            ->get();

        $revenue = collect($revenueRows)->keyBy(
            fn (array $row): string => $row['currency'].'|'.$row['exponent']
        );
        $usage = $usageRows->keyBy(
            fn ($row): string => $row->currency.'|'.(int) $row->currency_exponent
        );

        $keys = $revenue->keys()->merge($usage->keys())->unique()->sort()->values();
        $byCurrency = $keys->map(function (string $key) use ($revenue, $usage): array {
            $revenueRow = $revenue->get($key);
            $usageRow = $usage->get($key);
            [$currency, $exponent] = explode('|', $key, 2);

            $salesRevenue = (int) ($revenueRow['minor'] ?? 0);
            $upstreamCost = (int) ($usageRow?->upstream_cost_minor ?? 0);
            $knownCreditRevenue = (int) ($usageRow?->known_credit_revenue_minor ?? 0);
            $knownCreditProfit = (int) ($usageRow?->known_credit_profit_minor ?? 0);
            $knownCreditMarginBps = $knownCreditRevenue > 0
                ? (int) floor(($knownCreditProfit * 10_000) / $knownCreditRevenue)
                : null;

            return [
                'currency' => $currency,
                'exponent' => (int) $exponent,
                'fulfilled_sales_revenue_minor' => (string) $salesRevenue,
                'customer_usage_value_minor' => (string) ((int) ($usageRow?->customer_usage_value_minor ?? 0)),
                'upstream_cost_minor' => (string) $upstreamCost,
                'gross_position_minor' => (string) ($salesRevenue - $upstreamCost),
                'known_credit_profit_minor' => (string) $knownCreditProfit,
                'known_credit_margin_bps' => $knownCreditMarginBps,
                'settled_records' => (int) ($usageRow?->settled_records ?? 0),
                'costed_records' => (int) ($usageRow?->costed_records ?? 0),
                'unknown_cost_records' => (int) ($usageRow?->unknown_cost_records ?? 0),
                'token_quota_records' => (int) ($usageRow?->token_quota_records ?? 0),
            ];
        })->values();

        return [
            'visibility' => 'ADMIN_ONLY',
            'by_currency' => $byCurrency,
            'unknown_upstream_cost_records' => (int) UsageRecord::query()->whereNull('upstream_cost_minor')->count(),
            'costed_usage_records' => (int) UsageRecord::query()->whereNotNull('upstream_cost_minor')->count(),
            'note' => 'Gross position is fulfilled sales revenue minus measured upstream cost incurred so far; prepaid balances may still create future cost.',
        ];
    }
}
