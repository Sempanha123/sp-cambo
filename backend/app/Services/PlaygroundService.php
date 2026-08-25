<?php

namespace App\Services;

use App\Exceptions\PlaygroundException;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundCredential;
use App\Models\PlaygroundSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PlaygroundService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ApiKeySecretService $secrets,
    ) {}

    /**
     * Hosted Playground funding order is intentionally:
     * daily free -> redeem-code bonus -> purchased/promotion entitlement.
     * Daily free is never available to ordinary customer API keys.
     *
     * @return array<string,mixed>
     */
    public function quota(User $user): array
    {
        $setting = PlaygroundSetting::current();
        $freeAliases = $this->freeAliases($setting);
        $limit = $setting->enabled ? max(0, (int) $setting->daily_token_quota) : 0;
        $daily = null;

        if ($setting->enabled && $limit > 0 && $freeAliases !== []) {
            $daily = $this->ensureDailyLot($user, $limit, $freeAliases);
        }

        $lots = EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
        $dailyRemaining = $daily ? $spendable($daily->fresh()) : 0;
        $redeemTokens = $lots->where('source_type', 'REDEEM_CODE')->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
        $paidTokens = $lots->whereIn('source_type', ['ORDER', 'PROMOTION', 'RESELLER_TRANSFER'])->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
        $paidCredit = $lots->whereIn('source_type', ['ORDER', 'PROMOTION', 'RESELLER_TRANSFER', 'REDEEM_CODE'])->where('billing_mode', 'CREDIT_BALANCE')->sum($spendable);

        $configuredDefault = is_string($setting->default_model_alias) ? $setting->default_model_alias : null;
        $defaultAlias = $configuredDefault !== null && in_array($configuredDefault, $freeAliases, true)
            ? $configuredDefault
            : ($freeAliases[0] ?? null);

        return [
            'enabled' => (bool) $setting->enabled,
            'limit' => $limit,
            'remaining' => (int) $dailyRemaining,
            'reset_at' => $daily?->expires_at?->toAtomString() ?? now()->addDay()->startOfDay()->toAtomString(),
            'max_output_tokens' => max(1, (int) $setting->max_output_tokens),
            'free_model_aliases' => $freeAliases,
            'redeem_token_remaining' => (int) $redeemTokens,
            'paid_token_remaining' => (int) $paidTokens,
            'paid_credit_remaining' => (int) $paidCredit,
            'fallback_available' => ($redeemTokens + $paidTokens + $paidCredit) > 0,
            'default_model_alias' => $defaultAlias,
            'allow_model_switching' => (bool) $setting->allow_model_switching,
        ];
    }

    /** @return array{response:mixed,message:string,request_id:string,quota:array<string,mixed>} */
    public function run(User $user, ModelAlias $alias, array $input): array
    {
        $quota = $this->quota($user);
        if (! $quota['enabled']) {
            throw new PlaygroundException('playground_disabled', 'The hosted Playground is currently disabled.', 503);
        }

        $setting = PlaygroundSetting::current();
        $lockedAlias = $quota['default_model_alias'] ?? null;
        if (! $setting->allow_model_switching && $lockedAlias !== null && $alias->public_alias !== $lockedAlias) {
            throw new PlaygroundException(
                'playground_model_locked',
                'This Playground is configured to use a single model.',
                422
            );
        }

        if (! $this->hasFundingForAlias($user, $alias->public_alias)) {
            throw new PlaygroundException(
                'playground_quota_exhausted',
                'Your daily free quota and available redeem/paid balance are exhausted for this model.',
                402
            );
        }

        $credential = $this->credential($user);
        $key = $credential->apiKey()->firstOrFail();
        $key->modelAliases()->syncWithoutDetaching([$alias->id]);

        if ($key->status !== 'ACTIVE' || $key->revoked_at !== null || $key->expires_at?->isPast()) {
            $key->forceFill(['status' => 'ACTIVE', 'revoked_at' => null, 'expires_at' => null])->save();
        }

        $path = match ($input['protocol']) {
            'messages' => '/v1/messages',
            'responses' => '/v1/responses',
            'chat_completions' => '/v1/chat/completions',
            default => throw new PlaygroundException('invalid_protocol', 'The selected protocol is not supported.', 422),
        };

        $input['max_output_tokens'] = min((int) $input['max_output_tokens'], max(1, (int) $setting->max_output_tokens));
        $body = $this->body($alias->public_alias, $input);
        $requestId = 'pg_'.Str::lower(Str::random(24));
        $base = rtrim((string) ($setting->gateway_base_url ?: config('services.spcambo.gateway_base_url')), '/');
        if ($base === '') {
            throw new PlaygroundException('playground_unavailable', 'The Playground inference service is not configured.', 503);
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withToken($credential->secret_ciphertext)
                ->withHeaders(['X-Request-Id' => $requestId])
                ->timeout((int) config('services.spcambo.playground_timeout_seconds', 90));

            if ($input['protocol'] === 'messages') {
                $request = $request->withHeaders(['anthropic-version' => '2023-06-01']);
            }

            $response = $request->post($base.$path, $body);
        } catch (ConnectionException) {
            throw new PlaygroundException('playground_unavailable', 'The inference gateway is temporarily unavailable.', 503);
        }

        if (! $response->successful()) {
            $json = $response->json();
            $code = is_array($json) && is_string($json['code'] ?? null) ? $json['code'] : 'playground_request_failed';
            $message = is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : 'The Playground request could not be completed.';
            throw new PlaygroundException($code, $message, max(400, min(599, $response->status())));
        }

        $payload = $response->json();

        return [
            'response' => $payload,
            'message' => $this->extractText($payload, (string) $input['protocol']),
            'request_id' => $requestId,
            'quota' => $this->quota($user),
        ];
    }

    /** @param array<int,string> $aliases */
    private function ensureDailyLot(User $user, int $limit, array $aliases): EntitlementLot
    {
        $day = now()->format('Y-m-d');
        $sourceId = "playground:{$user->id}:{$day}";

        return DB::transaction(function () use ($user, $limit, $aliases, $sourceId, $day): EntitlementLot {
            $existing = EntitlementLot::query()
                ->where('user_id', $user->id)
                ->where('source_type', 'PLAYGROUND_DAILY')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $delta = $limit - (int) $existing->original_units;
                $remaining = max((int) $existing->reserved_units, (int) $existing->remaining_units + $delta);
                $existing->forceFill([
                    'original_units' => $limit,
                    'remaining_units' => $remaining,
                    'allowed_model_aliases' => $aliases,
                ])->save();
                return $existing;
            }

            return $this->entitlements->grant($user, [
                'source_type' => 'PLAYGROUND_DAILY',
                'source_id' => $sourceId,
                'package_name' => 'Daily Playground',
                'family_label' => 'Playground',
                'billing_mode' => 'TOKEN_QUOTA',
                'original_units' => $limit,
                'unit_label' => 'tokens',
                'currency' => null,
                'currency_exponent' => null,
                'allowed_model_aliases' => $aliases,
                'billing_snapshot' => ['billing_rules' => []],
                'activated_at' => now(),
                'expires_at' => now()->addDay()->startOfDay(),
            ], "playground-daily:{$user->id}:{$day}");
        });
    }

    private function hasFundingForAlias(User $user, string $alias): bool
    {
        return EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereJsonContains('allowed_model_aliases', $alias)
            ->whereColumn('remaining_units', '>', 'reserved_units')
            ->exists();
    }

    /** @return array<int,string> */
    private function freeAliases(PlaygroundSetting $setting): array
    {
        $configured = array_values(array_filter(array_unique($setting->allowed_model_aliases ?? []), 'is_string'));
        if ($configured === []) {
            return [];
        }

        return ModelAlias::query()->published()->whereIn('public_alias', $configured)->pluck('public_alias')->values()->all();
    }

    private function credential(User $user): PlaygroundCredential
    {
        return DB::transaction(function () use ($user): PlaygroundCredential {
            $existing = PlaygroundCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $created = $this->secrets->create($user, [
                'label' => 'System Playground credential',
                'requests_per_minute' => 12,
                'tokens_per_minute' => 40000,
                'concurrency_limit' => 1,
                'max_request_bytes' => 131072,
                'max_output_tokens' => 65536,
            ], []);

            return PlaygroundCredential::query()->create([
                'user_id' => $user->id,
                'api_key_id' => $created['key']->id,
                'secret_ciphertext' => $created['secret'],
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function body(string $model, array $input): array
    {
        $system = trim((string) ($input['system_prompt'] ?? ''));
        $max = (int) $input['max_output_tokens'];
        $temperature = array_key_exists('temperature', $input) ? $input['temperature'] : null;
        $messages = $this->conversation($input);

        return match ($input['protocol']) {
            'messages' => array_filter([
                'model' => $model,
                'max_tokens' => $max,
                'system' => $system === '' ? null : $system,
                'messages' => $messages,
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
            'responses' => array_filter([
                'model' => $model,
                'instructions' => $system === '' ? null : $system,
                'input' => $messages,
                'max_output_tokens' => $max,
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
            'chat_completions' => array_filter([
                'model' => $model,
                'messages' => array_values(array_filter([
                    $system === '' ? null : ['role' => 'system', 'content' => $system],
                    ...$messages,
                ])),
                'max_tokens' => $max,
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
        };
    }

    /** @return array<int,array{role:string,content:string}> */
    private function conversation(array $input): array
    {
        $rows = is_array($input['messages'] ?? null) ? $input['messages'] : [];
        if ($rows === []) {
            $prompt = trim((string) ($input['prompt'] ?? ''));
            return [['role' => 'user', 'content' => $prompt]];
        }

        // Keep browser chat history bounded and normalize consecutive roles so the
        // same transcript works on Anthropic Messages, Responses and Chat Completions.
        $normalized = [];
        foreach (array_slice($rows, -30) as $row) {
            if (! is_array($row)) continue;
            $role = ($row['role'] ?? null) === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') continue;

            $last = array_key_last($normalized);
            if ($last !== null && $normalized[$last]['role'] === $role) {
                $normalized[$last]['content'] .= "\n\n".$content;
            } else {
                $normalized[] = ['role' => $role, 'content' => $content];
            }
        }

        if ($normalized === []) {
            throw new PlaygroundException('invalid_prompt', 'Write a message before sending it.', 422);
        }

        return $normalized;
    }

    private function extractText(mixed $payload, string $protocol): string
    {
        if (! is_array($payload)) {
            return '';
        }

        if ($protocol === 'messages') {
            $parts = [];
            foreach (($payload['content'] ?? []) as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    $parts[] = $item['text'];
                }
            }
            return trim(implode("\n", $parts));
        }

        if ($protocol === 'chat_completions') {
            $content = $payload['choices'][0]['message']['content'] ?? null;
            if (is_string($content)) {
                return trim($content);
            }
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $item) {
                    if (is_array($item) && is_string($item['text'] ?? null)) $parts[] = $item['text'];
                }
                return trim(implode("\n", $parts));
            }
            return '';
        }

        if (is_string($payload['output_text'] ?? null)) {
            return trim($payload['output_text']);
        }

        $parts = [];
        foreach (($payload['output'] ?? []) as $output) {
            if (! is_array($output)) continue;
            foreach (($output['content'] ?? []) as $item) {
                if (! is_array($item)) continue;
                $text = $item['text'] ?? $item['output_text'] ?? null;
                if (is_string($text)) $parts[] = $text;
            }
        }

        return trim(implode("\n", $parts));
    }

}
