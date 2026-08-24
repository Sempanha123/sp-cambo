<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsageController extends Controller
{
    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'key_id' => ['sometimes', 'string', 'max:64'],
        ]);
        $query = ApiRequestLog::query()->where('user_id', $request->user()->id)->with(['apiKey', 'usage'])->latest('started_at');
        if (isset($data['model'])) {
            $query->where('public_model', $data['model']);
        }
        if (isset($data['key_id'])) {
            $query->where('api_key_id', $data['key_id']);
        }

        return response()->json(['data' => $query->limit($data['limit'] ?? 25)->get()->map(function (ApiRequestLog $log): array {
            $usage = $log->usage;
            $estimated = $usage === null && $log->estimated_units !== null;

            return ['id' => $log->id, 'public_model' => $log->public_model, 'api_key_id' => $log->api_key_id, 'api_key_label' => $log->apiKey?->label ?? 'Deleted key', 'api_key_prefix' => $log->apiKey?->prefix ?? '', 'state' => strtolower($log->state), 'endpoint' => $log->endpoint, 'started_at' => $log->started_at->toAtomString(), 'duration_ms' => $log->duration_ms, 'input_tokens' => $usage?->input_tokens, 'output_tokens' => $usage?->output_tokens, 'cache_read_tokens' => $usage?->cache_read_tokens, 'cache_write_tokens' => $usage?->cache_write_tokens, 'reasoning_tokens' => $usage?->reasoning_tokens, 'total_tokens' => $usage?->total_tokens, 'metered_units' => $usage ? (string) $usage->metered_units : ($estimated ? (string) $log->estimated_units : null), 'credit_charge' => $usage?->credit_charge_minor === null ? null : ['minor' => (string) $usage->credit_charge_minor, 'currency' => $usage->currency, 'exponent' => $usage->currency_exponent], 'estimated' => $estimated, 'error_code' => $log->error_code];
        })->values()]);
    }

    /**
     * Get per-key usage summary for the authenticated user.
     */
    public function keySummary(Request $request, string $keyId): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'bucket' => ['sometimes', Rule::in(['hour', 'day'])],
        ]);

        $key = ApiKey::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $keyId)
            ->firstOrFail();

        $bucket = $data['bucket'] ?? 'hour';
        $toRaw = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : CarbonImmutable::now('UTC');
        $to = $bucket === 'hour' ? $toRaw->startOfHour() : $toRaw->startOfDay();
        if ($toRaw->greaterThan($to)) {
            $to = $bucket === 'hour' ? $to->addHour() : $to->addDay();
        }
        $defaultSeconds = $bucket === 'hour' ? 86400 : 30 * 86400;
        $fromRaw = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $toRaw->subSeconds($defaultSeconds);
        $from = $bucket === 'hour' ? $fromRaw->startOfHour() : $fromRaw->startOfDay();
        $maxBuckets = $bucket === 'hour' ? 744 : 366;
        $minimumFrom = $bucket === 'hour' ? $to->subHours($maxBuckets) : $to->subDays($maxBuckets);
        if ($from->lessThan($minimumFrom)) {
            $from = $minimumFrom;
        }
        if ($from->greaterThanOrEqualTo($to)) {
            $from = $bucket === 'hour' ? $to->subHour() : $to->subDay();
        }

        $records = UsageRecord::query()
            ->where('user_id', $request->user()->id)
            ->where('api_key_id', $keyId)
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $to)
            ->orderBy('settled_at')
            ->get();

        $buckets = [];
        for ($cursor = $from; $cursor->lessThan($to); $cursor = $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay()) {
            $buckets[$cursor->toAtomString()] = ['at' => $cursor->toAtomString(), 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'metered_units' => 0];
        }

        $byModel = [];
        $totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'metered_units' => 0, 'credit_charge_minor' => 0];
        $currency = 'USD';
        $exponent = 2;

        foreach ($records as $record) {
            $at = $bucket === 'hour' ? $record->settled_at->startOfHour() : $record->settled_at->startOfDay();
            $key = $at->toAtomString();
            $buckets[$key]['requests']++;
            $buckets[$key]['input_tokens'] += (int) $record->input_tokens;
            $buckets[$key]['output_tokens'] += (int) $record->output_tokens;
            $buckets[$key]['metered_units'] += (int) $record->metered_units;

            $model = $byModel[$record->public_model] ?? ['public_model' => $record->public_model, 'requests' => 0, 'metered_units' => 0, 'credit_charge_minor' => 0];
            $model['requests']++;
            $model['metered_units'] += (int) $record->metered_units;
            $model['credit_charge_minor'] += (int) ($record->credit_charge_minor ?? 0);
            $byModel[$record->public_model] = $model;

            $totals['requests']++;
            $totals['input_tokens'] += (int) $record->input_tokens;
            $totals['output_tokens'] += (int) $record->output_tokens;
            $totals['metered_units'] += (int) $record->metered_units;
            $totals['credit_charge_minor'] += (int) ($record->credit_charge_minor ?? 0);

            if ($record->currency !== null) {
                $currency = $record->currency;
                $exponent = (int) $record->currency_exponent;
            }
        }

        $money = fn (int $minor): array => ['minor' => (string) $minor, 'currency' => $currency, 'exponent' => $exponent];

        return response()->json(['data' => [
            'key' => $this->keySummaryResource($key),
            'range' => ['from' => $from->toAtomString(), 'to' => $to->toAtomString()],
            'requests' => $totals['requests'],
            'input_tokens' => $totals['input_tokens'],
            'output_tokens' => $totals['output_tokens'],
            'metered_units' => (string) $totals['metered_units'],
            'credit_charge' => $money($totals['credit_charge_minor']),
            'buckets' => collect($buckets)->map(function ($item): array {
                $item['metered_units'] = (string) $item['metered_units'];

                return $item;
            })->values(),
            'by_model' => collect($byModel)->map(fn ($item) => [
                'public_model' => $item['public_model'],
                'requests' => $item['requests'],
                'metered_units' => (string) $item['metered_units'],
                'credit_charge' => $money($item['credit_charge_minor']),
            ])->values(),
        ]]);
    }

    /**
     * Transform an API key for key summary response.
     */
    protected function keySummaryResource(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'label' => $key->label,
            'prefix' => $key->prefix,
            'last_four' => $key->last_four,
            'status' => $key->status,
            'created_at' => $key->created_at->toAtomString(),
            'last_used_at' => $key->last_used_at?->toAtomString(),
            'expires_at' => $key->expires_at?->toAtomString(),
            'allowed_model_aliases' => $key->allowed_model_aliases,
            'limits' => [
                'requests_per_minute' => $key->requests_per_minute,
                'tokens_per_minute' => $key->tokens_per_minute,
                'concurrency' => $key->concurrency_limit,
                'max_request_bytes' => $key->max_request_bytes,
                'max_output_tokens' => $key->max_output_tokens,
            ],
        ];
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'], 'bucket' => ['sometimes', Rule::in(['hour', 'day'])]]);
        $bucket = $data['bucket'] ?? 'hour';
        $toRaw = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : CarbonImmutable::now('UTC');
        $to = $bucket === 'hour' ? $toRaw->startOfHour() : $toRaw->startOfDay();
        if ($toRaw->greaterThan($to)) {
            $to = $bucket === 'hour' ? $to->addHour() : $to->addDay();
        }
        $defaultSeconds = $bucket === 'hour' ? 86400 : 30 * 86400;
        $fromRaw = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $toRaw->subSeconds($defaultSeconds);
        $from = $bucket === 'hour' ? $fromRaw->startOfHour() : $fromRaw->startOfDay();
        $maxBuckets = $bucket === 'hour' ? 744 : 366;
        $minimumFrom = $bucket === 'hour' ? $to->subHours($maxBuckets) : $to->subDays($maxBuckets);
        if ($from->lessThan($minimumFrom)) {
            $from = $minimumFrom;
        }
        if ($from->greaterThanOrEqualTo($to)) {
            $from = $bucket === 'hour' ? $to->subHour() : $to->subDay();
        }
        $records = UsageRecord::query()->where('user_id', $request->user()->id)->where('settled_at', '>=', $from)->where('settled_at', '<', $to)->orderBy('settled_at')->get();
        $buckets = [];
        for ($cursor = $from; $cursor->lessThan($to); $cursor = $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay()) {
            $buckets[$cursor->toAtomString()] = ['at' => $cursor->toAtomString(), 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'metered_units' => 0];
        }
        $byModel = [];
        $totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'metered_units' => 0, 'credit_charge_minor' => 0];
        $currency = 'USD';
        $exponent = 2;
        foreach ($records as $record) {
            $at = $bucket === 'hour' ? $record->settled_at->startOfHour() : $record->settled_at->startOfDay();
            $key = $at->toAtomString();
            $buckets[$key]['requests']++;
            $buckets[$key]['input_tokens'] += (int) $record->input_tokens;
            $buckets[$key]['output_tokens'] += (int) $record->output_tokens;
            $buckets[$key]['metered_units'] += (int) $record->metered_units;
            $model = $byModel[$record->public_model] ?? ['public_model' => $record->public_model, 'requests' => 0, 'metered_units' => 0, 'credit_charge_minor' => 0];
            $model['requests']++;
            $model['metered_units'] += (int) $record->metered_units;
            $model['credit_charge_minor'] += (int) ($record->credit_charge_minor ?? 0);
            $byModel[$record->public_model] = $model;
            $totals['requests']++;
            $totals['input_tokens'] += (int) $record->input_tokens;
            $totals['output_tokens'] += (int) $record->output_tokens;
            $totals['metered_units'] += (int) $record->metered_units;
            $totals['credit_charge_minor'] += (int) ($record->credit_charge_minor ?? 0);
            if ($record->currency !== null) {
                $currency = $record->currency;
                $exponent = (int) $record->currency_exponent;
            }
        }
        $money = fn (int $minor): array => ['minor' => (string) $minor, 'currency' => $currency, 'exponent' => $exponent];

        return response()->json(['data' => ['range' => ['from' => $from->toAtomString(), 'to' => $to->toAtomString()], 'requests' => $totals['requests'], 'input_tokens' => $totals['input_tokens'], 'output_tokens' => $totals['output_tokens'], 'metered_units' => (string) $totals['metered_units'], 'credit_charge' => $money($totals['credit_charge_minor']), 'buckets' => collect($buckets)->map(function ($item): array {
            $item['metered_units'] = (string) $item['metered_units'];

            return $item;
        })->values(), 'by_model' => collect($byModel)->map(fn ($item) => ['public_model' => $item['public_model'], 'requests' => $item['requests'], 'metered_units' => (string) $item['metered_units'], 'credit_charge' => $money($item['credit_charge_minor'])])->values()]]);
    }
}
