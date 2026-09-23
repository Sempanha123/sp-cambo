<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EntitlementLot;
use App\Models\ResellerCustomer;
use App\Models\ResellerTransfer;
use App\Models\UsageRecord;
use App\Services\ResellerAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResellerCustomerReportingController extends Controller
{
    public function allocations(Request $request, string $resellerCustomer): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'billing_mode' => [
                'sometimes',
                Rule::in(['TOKEN_QUOTA', 'CREDIT_BALANCE']),
            ],
            'model' => ['sometimes', 'string', 'max:100'],
        ]);

        $managed = $this->managedCustomer($request, $resellerCustomer);

        $query = ResellerTransfer::query()
            ->where('reseller_user_id', $request->user()->id)
            ->where('customer_user_id', $managed->customer_user_id)
            ->with('allocations')
            ->latest('created_at')
            ->latest('id');

        if (isset($data['billing_mode'])) {
            $query->where('billing_mode', $data['billing_mode']);
        }

        /*
         * Historical model-scoped transfers can still be filtered directly.
         * Package-level transfers are reported with their full model list below.
         */
        if (isset($data['model'])) {
            $query->where('public_model_alias', $data['model']);
        }

        $limit = (int) ($data['limit'] ?? 50);
        $rows = $query->limit($limit)->get();

        $targetIds = $rows
            ->flatMap(fn (ResellerTransfer $transfer) =>
                $transfer->allocations->pluck('target_entitlement_lot_id')
            )
            ->filter()
            ->unique()
            ->values();

        $targets = EntitlementLot::query()
            ->whereIn('id', $targetIds)
            ->get()
            ->keyBy(fn (EntitlementLot $lot): string => (string) $lot->id);

        return response()->json([
            'data' => $rows->map(function (ResellerTransfer $transfer) use (
                $managed,
                $targets
            ): array {
                $allocation = $transfer->allocations->first();
                $target = $allocation
                    ? $targets->get((string) $allocation->target_entitlement_lot_id)
                    : null;

                $packageAllocation =
                    $transfer->public_model_alias
                    === ResellerAllocationService::PACKAGE_SCOPE_SENTINEL;

                $aliases = $target?->allowed_model_aliases ?? (
                    $packageAllocation
                        ? []
                        : [$transfer->public_model_alias]
                );

                return [
                    'id' => $transfer->id,
                    'customer_id' => (string) $managed->id,
                    'allocation_kind' => $packageAllocation ? 'PACKAGE' : 'MODEL',
                    'inventory_lot_id' => $packageAllocation && $allocation
                        ? (string) $allocation->source_entitlement_lot_id
                        : null,
                    'package_name' => $target?->package_name,
                    'billing_mode' => $transfer->billing_mode,
                    'public_model_alias' => $packageAllocation
                        ? null
                        : $transfer->public_model_alias,
                    'allowed_model_aliases' => array_values($aliases),
                    'units' => (string) $transfer->units,
                    'idempotency_key' => $transfer->idempotency_key,
                    'reason' => $transfer->reason,
                    'created_at' => $transfer->created_at->toAtomString(),
                ];
            })->values(),
            'meta' => [
                'limit' => $limit,
                'count' => $rows->count(),
            ],
        ]);
    }

    public function usage(Request $request, string $resellerCustomer): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'key_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $managed = $this->managedCustomer($request, $resellerCustomer);

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->utc()
            : CarbonImmutable::now('UTC');

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->utc()
            : $to->subDays(30);

        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages([
                'from' => ['The usage start time must be before the end time.'],
            ]);
        }

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages([
                'from' => ['A reseller usage query can cover at most 366 days.'],
            ]);
        }

        $base = UsageRecord::query()
            ->where('user_id', $managed->customer_user_id)
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $to);

        if (isset($data['model'])) {
            $base->where('public_model', $data['model']);
        }

        if (isset($data['key_id'])) {
            $base->where('api_key_id', $data['key_id']);
        }

        $totals = (clone $base)
            ->selectRaw(
                'COUNT(*) as requests,
                 COALESCE(SUM(input_tokens), 0) as input_tokens,
                 COALESCE(SUM(output_tokens), 0) as output_tokens,
                 COALESCE(SUM(cache_read_tokens), 0) as cache_read_tokens,
                 COALESCE(SUM(cache_write_tokens), 0) as cache_write_tokens,
                 COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens,
                 COALESCE(SUM(total_tokens), 0) as total_tokens,
                 COALESCE(SUM(metered_units), 0) as metered_units'
            )
            ->first();

        $byModel = (clone $base)
            ->select('public_model')
            ->selectRaw(
                'COUNT(*) as requests,
                 COALESCE(SUM(input_tokens), 0) as input_tokens,
                 COALESCE(SUM(output_tokens), 0) as output_tokens,
                 COALESCE(SUM(cache_read_tokens), 0) as cache_read_tokens,
                 COALESCE(SUM(cache_write_tokens), 0) as cache_write_tokens,
                 COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens,
                 COALESCE(SUM(total_tokens), 0) as total_tokens,
                 COALESCE(SUM(metered_units), 0) as metered_units'
            )
            ->groupBy('public_model')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row): array => [
                'public_model' => $row->public_model,
                'requests' => (int) $row->requests,
                'input_tokens' => (string) $row->input_tokens,
                'output_tokens' => (string) $row->output_tokens,
                'cache_read_tokens' => (string) $row->cache_read_tokens,
                'cache_write_tokens' => (string) $row->cache_write_tokens,
                'reasoning_tokens' => (string) $row->reasoning_tokens,
                'total_tokens' => (string) $row->total_tokens,
                'metered_units' => (string) $row->metered_units,
            ])
            ->values();

        $creditCharges = (clone $base)
            ->whereNotNull('credit_charge_minor')
            ->whereNotNull('currency')
            ->select(['currency', 'currency_exponent'])
            ->selectRaw('COALESCE(SUM(credit_charge_minor), 0) as minor')
            ->groupBy('currency', 'currency_exponent')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'minor' => (string) $row->minor,
                'currency' => $row->currency,
                'exponent' => (int) ($row->currency_exponent ?? 2),
            ])
            ->values();

        $limit = (int) ($data['limit'] ?? 25);

        $recent = (clone $base)
            ->latest('settled_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (UsageRecord $record): array => [
                'id' => $record->id,
                'api_key_id' => $record->api_key_id,
                'public_model' => $record->public_model,
                'endpoint' => $record->endpoint,
                'input_tokens' => (string) $record->input_tokens,
                'output_tokens' => (string) $record->output_tokens,
                'cache_read_tokens' => (string) $record->cache_read_tokens,
                'cache_write_tokens' => (string) $record->cache_write_tokens,
                'reasoning_tokens' => (string) $record->reasoning_tokens,
                'total_tokens' => (string) $record->total_tokens,
                'metered_units' => (string) $record->metered_units,
                'credit_charge' => $record->credit_charge_minor === null
                    ? null
                    : [
                        'minor' => (string) $record->credit_charge_minor,
                        'currency' => $record->currency,
                        'exponent' => (int) ($record->currency_exponent ?? 2),
                    ],
                'settled_at' => $record->settled_at->toAtomString(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'customer_id' => (string) $managed->id,
                'range' => [
                    'from' => $from->toAtomString(),
                    'to' => $to->toAtomString(),
                ],
                'totals' => [
                    'requests' => (int) ($totals?->requests ?? 0),
                    'input_tokens' => (string) ($totals?->input_tokens ?? 0),
                    'output_tokens' => (string) ($totals?->output_tokens ?? 0),
                    'cache_read_tokens' => (string) ($totals?->cache_read_tokens ?? 0),
                    'cache_write_tokens' => (string) ($totals?->cache_write_tokens ?? 0),
                    'reasoning_tokens' => (string) ($totals?->reasoning_tokens ?? 0),
                    'total_tokens' => (string) ($totals?->total_tokens ?? 0),
                    'metered_units' => (string) ($totals?->metered_units ?? 0),
                ],
                'credit_charges' => $creditCharges,
                'by_model' => $byModel,
                'recent' => $recent,
            ],
            'meta' => [
                'recent_limit' => $limit,
            ],
        ]);
    }

    private function managedCustomer(
        Request $request,
        string $managedId
    ): ResellerCustomer {
        return ResellerCustomer::query()
            ->where('reseller_user_id', $request->user()->id)
            ->findOrFail($managedId);
    }
}
