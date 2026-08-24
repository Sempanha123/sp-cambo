<?php

namespace App\Services;

use App\Exceptions\PlaygroundException;

use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundCredential;
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

    /** @return array{limit:int,remaining:int,reset_at:string,enabled:bool} */
    public function quota(User $user): array
    {
        $limit = max(0, (int) config('services.spcambo.playground_daily_token_quota', 20000));
        if ($limit === 0) {
            return ['limit' => 0, 'remaining' => 0, 'reset_at' => now()->addDay()->startOfDay()->toAtomString(), 'enabled' => false];
        }

        $lot = $this->ensureDailyLot($user, $limit);
        $remaining = max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);

        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_at' => $lot->expires_at->toAtomString(),
            'enabled' => true,
        ];
    }

    /** @return array{response:mixed,request_id:string,quota:array{limit:int,remaining:int,reset_at:string,enabled:bool}} */
    public function run(User $user, ModelAlias $alias, array $input): array
    {
        $quota = $this->quota($user);
        if (! $quota['enabled'] || $quota['remaining'] <= 0) {
            throw new PlaygroundException('playground_quota_exhausted', 'Your free Playground quota is exhausted for today.', 402);
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

        $body = $this->body($alias->public_alias, $input);
        $requestId = 'pg_'.Str::lower(Str::random(24));
        $base = rtrim((string) config('services.spcambo.gateway_base_url'), '/');
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

        return [
            'response' => $response->json(),
            'request_id' => $requestId,
            'quota' => $this->quota($user),
        ];
    }

    private function ensureDailyLot(User $user, int $limit): EntitlementLot
    {
        $day = now()->format('Y-m-d');
        $sourceId = "playground:{$user->id}:{$day}";
        $existing = EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->where('source_id', $sourceId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $aliases = ModelAlias::query()->published()->pluck('public_alias')->values()->all();
        if ($aliases === []) {
            throw new PlaygroundException('model_unavailable', 'No Playground model is currently published.', 503);
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
                'max_output_tokens' => 2048,
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
        $prompt = trim((string) $input['prompt']);
        $system = trim((string) ($input['system_prompt'] ?? ''));
        $max = (int) $input['max_output_tokens'];
        $temperature = array_key_exists('temperature', $input) ? $input['temperature'] : null;

        return match ($input['protocol']) {
            'messages' => array_filter([
                'model' => $model,
                'max_tokens' => $max,
                'system' => $system === '' ? null : $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
            'responses' => array_filter([
                'model' => $model,
                'instructions' => $system === '' ? null : $system,
                'input' => $prompt,
                'max_output_tokens' => $max,
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
            'chat_completions' => array_filter([
                'model' => $model,
                'messages' => array_values(array_filter([
                    $system === '' ? null : ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ])),
                'max_tokens' => $max,
                'temperature' => $temperature,
                'stream' => false,
            ], fn ($value) => $value !== null),
        };
    }
}
