<?php

namespace App\Services;

use App\Exceptions\PlaygroundException;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundCredential;
use App\Models\PlaygroundSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaygroundService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ApiKeySecretService $secrets,
    ) {}

    /**
     * The Playground always spends the isolated daily lot first. Paid/redeemed
     * customer balance is exposed only as an explicit fallback after the daily
     * allowance is unavailable, matching the release contract without silently
     * consuming purchased capacity.
     *
     * @return array<string,mixed>
     */
    public function quota(User $user): array
    {
        $setting = PlaygroundSetting::current();
        $configuredFreeAliases = $this->configuredFreeAliases($setting);
        $freeAliases = $this->publishedChatAliases($configuredFreeAliases);
        $limit = $setting->enabled ? max(0, (int) $setting->daily_token_quota) : 0;
        $daily = null;

        if ($setting->enabled && $limit > 0) {
            // Daily allowance and model availability are separate facts. Keep the
            // day's lot aligned with the configured allowance even while the model
            // is temporarily unpublished. This also repairs stale lots created by
            // older Fix builds: ensureDailyLot adds only the configuration delta,
            // so genuinely spent tokens are never reset.
            $daily = $configuredFreeAliases !== []
                ? $this->ensureDailyLot($user, $limit, $configuredFreeAliases)
                : $this->currentDailyLot($user);
        }

        $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
        $dailyRemaining = $daily
            ? $spendable($daily->fresh())
            : (($setting->enabled && $limit > 0) ? $limit : 0);
        $fallbackLots = $this->allFallbackLots($user);
        $redeemTokenRemaining = $fallbackLots
            ->where('source_type', 'REDEEM_CODE')
            ->where('billing_mode', 'TOKEN_QUOTA')
            ->sum($spendable);
        $paidTokenRemaining = $fallbackLots
            ->where('billing_mode', 'TOKEN_QUOTA')
            ->reject(fn (EntitlementLot $lot): bool => $lot->source_type === 'REDEEM_CODE')
            ->sum($spendable);
        $paidCreditRemaining = $fallbackLots
            ->where('billing_mode', 'CREDIT_BALANCE')
            ->sum($spendable);

        $fallbackAliasCandidates = $fallbackLots
            ->flatMap(fn (EntitlementLot $lot): array => is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [])
            ->filter(static fn ($value): bool => is_string($value))
            ->unique()
            ->values()
            ->all();
        $fallbackAliases = $this->publishedChatAliases($fallbackAliasCandidates);
        $availableAliases = collect([...$freeAliases, ...$fallbackAliases])->unique()->values()->all();
        $fundedModelStatuses = $this->fundedModelStatuses($fallbackAliasCandidates, $fallbackLots, $spendable);
        $unavailableFundedModels = array_values(array_filter(
            $fundedModelStatuses,
            static fn (array $row): bool => ($row['available'] ?? false) !== true
        ));

        $modelBalances = collect($availableAliases)->map(function (string $alias) use ($fallbackLots, $freeAliases, $spendable): array {
            $lots = $fallbackLots->filter(fn (EntitlementLot $lot): bool => in_array($alias, $lot->allowed_model_aliases ?? [], true));
            $tokenRemaining = $lots->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
            $creditRemaining = $lots->where('billing_mode', 'CREDIT_BALANCE')->sum($spendable);
            $nextExpiry = $lots->whereNotNull('expires_at')->sortBy('expires_at')->first()?->expires_at;

            return [
                'alias' => $alias,
                'free_eligible' => in_array($alias, $freeAliases, true),
                'balance_available' => $tokenRemaining > 0 || $creditRemaining > 0,
                'token_remaining' => (int) $tokenRemaining,
                'credit_remaining' => (int) $creditRemaining,
                'next_expires_at' => $nextExpiry?->toAtomString(),
            ];
        })->values()->all();

        $configuredDefault = is_string($setting->default_model_alias) ? $setting->default_model_alias : null;
        $defaultAlias = $configuredDefault !== null && in_array($configuredDefault, $availableAliases, true)
            ? $configuredDefault
            : ($freeAliases[0] ?? $fallbackAliases[0] ?? null);

        return [
            'enabled' => (bool) $setting->enabled,
            'limit' => $limit,
            'remaining' => (int) $dailyRemaining,
            'reset_at' => $daily?->expires_at?->toAtomString() ?? now()->addDay()->startOfDay()->toAtomString(),
            'max_output_tokens' => max(1, (int) $setting->max_output_tokens),
            'free_model_aliases' => $freeAliases,
            'free_models_available' => $freeAliases !== [],
            'free_model_message' => $freeAliases === [] && $setting->enabled && $limit > 0
                ? 'Your daily free quota is untouched, but no published free Playground model is currently runnable.'
                : null,
            'redeem_token_remaining' => (int) $redeemTokenRemaining,
            'paid_token_remaining' => (int) $paidTokenRemaining,
            'paid_credit_remaining' => (int) $paidCreditRemaining,
            'fallback_available' => $fallbackAliases !== [],
            'fallback_model_aliases' => $fallbackAliases,
            'available_model_aliases' => $availableAliases,
            'available_models' => $this->availableModelSummaries($availableAliases),
            'funded_model_statuses' => $fundedModelStatuses,
            'unavailable_funded_models' => $unavailableFundedModels,
            'model_balances' => $modelBalances,
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
        if (! in_array($alias->public_alias, $quota['available_model_aliases'], true)) {
            throw new PlaygroundException(
                'playground_model_not_allowed',
                'The selected model is not available in your free or purchased Playground access.',
                403
            );
        }
        if (! $setting->allow_model_switching
            && $lockedAlias !== null
            && $alias->public_alias !== $lockedAlias
            && ! in_array($alias->public_alias, $quota['fallback_model_aliases'], true)) {
            throw new PlaygroundException(
                'playground_model_locked',
                'This Playground is configured to use a single model.',
                422
            );
        }

        $fundingSource = ($input['funding_source'] ?? 'daily') === 'balance' ? 'balance' : 'daily';
        if ($fundingSource === 'daily' && ! $this->hasDailyFundingForAlias($user, $alias->public_alias)) {
            throw new PlaygroundException(
                'playground_quota_exhausted',
                'Your daily free Playground quota is exhausted for this model. Choose the balance fallback to continue.',
                402
            );
        }
        if ($fundingSource === 'balance' && ! $this->hasBalanceFundingForAlias($user, $alias->public_alias)) {
            throw new PlaygroundException(
                'playground_balance_exhausted',
                'No redeemed, purchased token, or credit balance is available for this Playground model.',
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
                ->withHeaders([
                    'X-Request-Id' => $requestId,
                    // The hidden Playground credential is the only credential that
                    // may use this scope. The control plane re-validates that fact.
                    'X-SP-Cambo-Playground-Funding' => $fundingSource === 'balance' ? 'BALANCE' : 'DAILY',
                ])
                ->timeout((int) config('services.spcambo.playground_timeout_seconds', 90));

            if ($input['protocol'] === 'messages') {
                $request = $request->withHeaders(['anthropic-version' => '2023-06-01']);
            }

            $response = $request->post($base.$path, $body);
        } catch (ConnectionException) {
            throw new PlaygroundException('playground_unavailable', 'The inference gateway is temporarily unavailable.', 503);
        }

        if (! $response->successful()) {
            [$code, $message] = $this->gatewayErrorDetails($response);
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


    public function stream(User $user, ModelAlias $alias, array $input): StreamedResponse
    {
        $quota = $this->quota($user);
        if (! $quota['enabled']) {
            throw new PlaygroundException('playground_disabled', 'The hosted Playground is currently disabled.', 503);
        }

        $setting = PlaygroundSetting::current();
        $lockedAlias = $quota['default_model_alias'] ?? null;
        if (! in_array($alias->public_alias, $quota['available_model_aliases'], true)) {
            throw new PlaygroundException(
                'playground_model_not_allowed',
                'The selected model is not available in your free or purchased Playground access.',
                403
            );
        }
        if (! $setting->allow_model_switching
            && $lockedAlias !== null
            && $alias->public_alias !== $lockedAlias
            && ! in_array($alias->public_alias, $quota['fallback_model_aliases'], true)) {
            throw new PlaygroundException(
                'playground_model_locked',
                'This Playground is configured to use a single model.',
                422
            );
        }

        $fundingSource = ($input['funding_source'] ?? 'daily') === 'balance' ? 'balance' : 'daily';
        if ($fundingSource === 'daily' && ! $this->hasDailyFundingForAlias($user, $alias->public_alias)) {
            throw new PlaygroundException(
                'playground_quota_exhausted',
                'Your daily free Playground quota is exhausted for this model. Choose the balance fallback to continue.',
                402
            );
        }
        if ($fundingSource === 'balance' && ! $this->hasBalanceFundingForAlias($user, $alias->public_alias)) {
            throw new PlaygroundException(
                'playground_balance_exhausted',
                'No redeemed, purchased token, or credit balance is available for this Playground model.',
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
        $body = $this->body($alias->public_alias, $input, true);
        $requestId = 'pg_'.Str::lower(Str::random(24));
        $base = rtrim((string) ($setting->gateway_base_url ?: config('services.spcambo.gateway_base_url')), '/');
        if ($base === '') {
            throw new PlaygroundException('playground_unavailable', 'The Playground inference service is not configured.', 503);
        }

        $protocol = (string) $input['protocol'];
        $timeoutSeconds = max(10, (int) config('services.spcambo.playground_timeout_seconds', 90));

        return response()->stream(function () use ($credential, $fundingSource, $requestId, $base, $path, $body, $protocol, $timeoutSeconds): void {
            $this->emitSse('meta', [
                'request_id' => $requestId,
                'protocol' => $protocol,
                'streaming' => true,
            ]);

            try {
                $request = Http::acceptJson()
                    ->asJson()
                    ->withToken($credential->secret_ciphertext)
                    ->withHeaders([
                        'X-Request-Id' => $requestId,
                        'X-SP-Cambo-Playground-Funding' => $fundingSource === 'balance' ? 'BALANCE' : 'DAILY',
                    ])
                    ->timeout($timeoutSeconds)
                    ->withOptions(['stream' => true]);

                if ($protocol === 'messages') {
                    $request = $request->withHeaders(['anthropic-version' => '2023-06-01']);
                }

                $response = $request->post($base.$path, $body);
            } catch (ConnectionException) {
                $this->emitSse('error', [
                    'code' => 'playground_unavailable',
                    'message' => 'The inference gateway is temporarily unavailable.',
                    'request_id' => $requestId,
                ]);
                return;
            } catch (\Throwable) {
                $this->emitSse('error', [
                    'code' => 'playground_request_failed',
                    'message' => 'The Playground stream could not be started.',
                    'request_id' => $requestId,
                ]);
                return;
            }

            if (! $response->successful()) {
                [$code, $message] = $this->gatewayErrorDetails($response);
                $this->emitSse('error', [
                    'code' => $code,
                    'message' => $message,
                    'request_id' => $requestId,
                ]);
                return;
            }

            $stream = $response->toPsrResponse()->getBody();
            $buffer = '';
            $finalText = '';
            $eventCount = 0;

            try {
                while (! $stream->eof()) {
                    if (connection_aborted()) {
                        $stream->close();
                        return;
                    }
                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        usleep(10_000);
                        continue;
                    }
                    $buffer .= $chunk;

                    while (($frame = $this->takeSseFrame($buffer)) !== null) {
                        $eventCount++;
                        $data = $this->sseFrameData($frame);
                        if ($data === null || $data === '[DONE]') {
                            continue;
                        }
                        $payload = json_decode($data, true);
                        if (! is_array($payload)) {
                            continue;
                        }
                        $delta = $this->streamDelta($payload);
                        if ($delta !== '') {
                            $finalText .= $delta;
                            $this->emitSse('delta', ['text' => $delta]);
                        }
                    }
                }

                if (trim($buffer) !== '') {
                    $data = $this->sseFrameData($buffer);
                    if ($data !== null && $data !== '[DONE]') {
                        $payload = json_decode($data, true);
                        if (is_array($payload)) {
                            $delta = $this->streamDelta($payload);
                            if ($delta !== '') {
                                $finalText .= $delta;
                                $this->emitSse('delta', ['text' => $delta]);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                $this->emitSse('error', [
                    'code' => 'playground_stream_interrupted',
                    'message' => 'The model stream was interrupted. You can retry the request.',
                    'request_id' => $requestId,
                ]);
                return;
            }

            $this->emitSse('done', [
                'request_id' => $requestId,
                'protocol' => $protocol,
                'event_count' => $eventCount,
                'text_length' => mb_strlen($finalText),
                'response' => [
                    'streamed' => true,
                    'protocol' => $protocol,
                ],
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function emitSse(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    private function takeSseFrame(string &$buffer): ?string
    {
        if (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $delimiter = $match[0][0];
        $offset = $match[0][1];
        $frame = substr($buffer, 0, $offset + strlen($delimiter));
        $buffer = substr($buffer, $offset + strlen($delimiter));

        return $frame;
    }

    private function sseFrameData(string $frame): ?string
    {
        $lines = preg_split('/\r?\n/', trim($frame));
        if (! is_array($lines)) {
            return null;
        }

        $data = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        return $data === [] ? null : implode("\n", $data);
    }

    private function streamDelta(array $payload): string
    {
        // OpenAI Chat Completions / compatible OmniRoute adapters.
        $content = $payload['choices'][0]['delta']['content'] ?? null;
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    $parts[] = $item['text'];
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }

        // Anthropic Messages streaming.
        if (($payload['type'] ?? null) === 'content_block_delta') {
            $text = $payload['delta']['text'] ?? null;
            if (is_string($text)) {
                return $text;
            }
        }
        if (($payload['type'] ?? null) === 'content_block_start') {
            $text = $payload['content_block']['text'] ?? null;
            if (is_string($text)) {
                return $text;
            }
        }

        // OpenAI Responses streaming.
        if (($payload['type'] ?? null) === 'response.output_text.delta' && is_string($payload['delta'] ?? null)) {
            return $payload['delta'];
        }

        // A few OpenAI-compatible routers use a direct text/delta field.
        if (is_string($payload['delta'] ?? null)) {
            return $payload['delta'];
        }
        if (is_string($payload['text'] ?? null) && in_array($payload['type'] ?? null, ['text_delta', 'output_text_delta'], true)) {
            return $payload['text'];
        }

        return '';
    }

    /** @return array{0:string,1:string} */
    private function gatewayErrorDetails(Response $response): array
    {
        $payload = $response->json();
        $code = 'playground_request_failed';
        $message = 'The Playground request could not be completed.';

        if (is_array($payload)) {
            // Laravel/control-plane style errors.
            if (is_string($payload['code'] ?? null) && trim($payload['code']) !== '') {
                $code = trim($payload['code']);
            }
            if (is_string($payload['message'] ?? null) && trim($payload['message']) !== '') {
                $message = trim($payload['message']);
            }

            // Anthropic-compatible gateway errors are nested under `error`.
            // Preserve only the stable SP Cambo machine code and safe public
            // message; private OmniRoute diagnostics never cross the gateway.
            $error = $payload['error'] ?? null;
            if (is_array($error)) {
                $nestedCode = $error['sp_cambo_code'] ?? $error['code'] ?? null;
                if (is_string($nestedCode) && trim($nestedCode) !== '') {
                    $code = trim($nestedCode);
                }
                if (is_string($error['message'] ?? null) && trim($error['message']) !== '') {
                    $message = trim($error['message']);
                }
            }
        }

        $requestId = trim((string) $response->header('x-request-id'));
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $requestId) === 1) {
            $message .= ' Request reference: '.$requestId.'.';
        }

        return [$code, $message];
    }

    private function currentDailyLot(User $user): ?EntitlementLot
    {
        $sourceId = 'playground:'.$user->id.':'.now()->format('Y-m-d');

        return EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->where('source_id', $sourceId)
            ->first();
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
                    'access_scope' => 'PLAYGROUND',
                    'bound_api_key_id' => null,
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
                'access_scope' => 'PLAYGROUND',
                'bound_api_key_id' => null,
                'fulfillment_claim_id' => null,
                'activated_at' => now(),
                'expires_at' => now()->addDay()->startOfDay(),
            ], "playground-daily:{$user->id}:{$day}");
        });
    }

    private function hasDailyFundingForAlias(User $user, string $alias): bool
    {
        // Keep the customer read path portable across MySQL/MariaDB variants.
        // The authoritative reservation path still enforces funding atomically;
        // this preflight only decides what the Playground UI may offer.
        return EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')
            ->get()
            ->contains(fn (EntitlementLot $lot): bool =>
                in_array($alias, is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [], true)
                && (int) $lot->remaining_units > (int) $lot->reserved_units
            );
    }

    private function hasBalanceFundingForAlias(User $user, string $alias): bool
    {
        return $this->fallbackLots($user, [$alias])
            ->contains(fn (EntitlementLot $lot): bool => (int) $lot->remaining_units > (int) $lot->reserved_units);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,EntitlementLot> */
    private function allFallbackLots(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $rows = EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where('source_type', '!=', 'PLAYGROUND_DAILY')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('created_at')
            ->get();

        return new \Illuminate\Database\Eloquent\Collection(
            $rows->filter(function (EntitlementLot $lot): bool {
                $scope = strtoupper((string) ($lot->access_scope ?: 'ACCOUNT'));
                return in_array($scope, ['ACCOUNT', 'PLAYGROUND'], true)
                    && (int) $lot->remaining_units > (int) $lot->reserved_units;
            })->values()->all()
        );
    }

    /** @param array<int,string> $aliases @return array<int,string> */
    private function publishedChatAliases(array $aliases): array
    {
        if ($aliases === []) {
            return [];
        }

        return ModelAlias::query()->published()->whereIn('public_alias', $aliases)->get()
            ->filter(fn (ModelAlias $alias): bool =>
                ($alias->capabilities['messages_api'] ?? false) === true
                || ($alias->capabilities['responses_api'] ?? false) === true
                || ($alias->capabilities['chat_completions_api'] ?? false) === true
            )
            ->pluck('public_alias')
            ->values()
            ->all();
    }

    /** @param array<int,string> $aliases @return \Illuminate\Database\Eloquent\Collection<int,EntitlementLot> */
    private function fallbackLots(User $user, array $aliases): \Illuminate\Database\Eloquent\Collection
    {
        if ($aliases === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $wanted = array_fill_keys(array_values(array_filter($aliases, 'is_string')), true);
        $rows = $this->allFallbackLots($user)->filter(function (EntitlementLot $lot) use ($wanted): bool {
            foreach (is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [] as $alias) {
                if (is_string($alias) && isset($wanted[$alias])) {
                    return true;
                }
            }
            return false;
        })->values();

        return new \Illuminate\Database\Eloquent\Collection($rows->all());
    }

    /**
     * Explain purchased/redeemed model availability without exposing provider
     * credentials or internal route identifiers. A funded lot must never vanish
     * from the customer UI merely because its alias is temporarily not runnable.
     *
     * @param array<int,string> $aliases
     * @return array<int,array{public_alias:string,display_name:string,available:bool,reason:string|null,token_remaining:int,credit_remaining:int}>
     */
    private function fundedModelStatuses(array $aliases, \Illuminate\Database\Eloquent\Collection $lots, callable $spendable): array
    {
        $aliases = array_values(array_unique(array_filter($aliases, static fn ($value): bool => is_string($value) && trim($value) !== '')));
        if ($aliases === []) {
            return [];
        }

        $rows = ModelAlias::query()
            ->with(['model.provider.activeConnectionRevision'])
            ->whereIn('public_alias', $aliases)
            ->get()
            ->keyBy('public_alias');

        return collect($aliases)->map(function (string $alias) use ($rows, $lots, $spendable): array {
            /** @var ModelAlias|null $modelAlias */
            $modelAlias = $rows->get($alias);
            $matching = $lots->filter(fn (EntitlementLot $lot): bool => in_array($alias, is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [], true));
            $tokenRemaining = (int) $matching->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
            $creditRemaining = (int) $matching->where('billing_mode', 'CREDIT_BALANCE')->sum($spendable);

            $reason = null;
            if (! $modelAlias) {
                $reason = 'This purchased model is no longer present in the public model catalogue.';
            } elseif (! $modelAlias->enabled || ! $modelAlias->customer_visible || ! in_array($modelAlias->status, ['active', 'beta'], true)) {
                $reason = 'This purchased model is currently disabled for customer use.';
            } elseif (($modelAlias->capabilities['messages_api'] ?? false) !== true
                && ($modelAlias->capabilities['responses_api'] ?? false) !== true
                && ($modelAlias->capabilities['chat_completions_api'] ?? false) !== true) {
                $reason = 'This purchased model does not currently have a customer chat protocol enabled.';
            } elseif (! $modelAlias->model || ! $modelAlias->model->enabled) {
                $reason = 'This purchased model is temporarily unavailable.';
            } elseif ($modelAlias->model->commercial_resale_verified_at === null) {
                $reason = 'This purchased model is temporarily unavailable for resale.';
            } elseif (! $modelAlias->model->provider || ! $modelAlias->model->provider->enabled) {
                $reason = 'This purchased model is temporarily unavailable.';
            } elseif (! $modelAlias->model->provider->activeConnectionRevision
                || $modelAlias->model->provider->activeConnectionRevision->lifecycle_status !== \App\Models\ProviderConnectionRevision::STATUS_READY) {
                $reason = 'This purchased model route is temporarily unavailable.';
            }

            return [
                'public_alias' => $alias,
                'display_name' => $modelAlias?->display_name ?: $alias,
                'available' => $reason === null,
                'reason' => $reason,
                'token_remaining' => $tokenRemaining,
                'credit_remaining' => $creditRemaining,
            ];
        })->values()->all();
    }

    /** @param array<int,string> $aliases @return array<int,array<string,mixed>> */
    private function availableModelSummaries(array $aliases): array
    {
        if ($aliases === []) {
            return [];
        }

        return ModelAlias::query()
            ->published()
            ->whereIn('public_alias', $aliases)
            ->orderBy('display_name')
            ->get(['id', 'public_alias', 'display_name', 'capabilities', 'limits'])
            ->filter(fn (ModelAlias $alias): bool =>
                ($alias->capabilities['messages_api'] ?? false) === true
                || ($alias->capabilities['responses_api'] ?? false) === true
                || ($alias->capabilities['chat_completions_api'] ?? false) === true
            )
            ->map(fn (ModelAlias $alias): array => [
                'public_alias' => $alias->public_alias,
                'display_name' => $alias->display_name,
                'capabilities' => $alias->capabilities,
                'limits' => $alias->limits,
            ])
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    private function configuredFreeAliases(PlaygroundSetting $setting): array
    {
        return array_values(array_filter(
            array_unique($setting->allowed_model_aliases ?? []),
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));
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
            ], [], false);

            return PlaygroundCredential::query()->create([
                'user_id' => $user->id,
                'api_key_id' => $created['key']->id,
                'secret_ciphertext' => $created['secret'],
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function body(string $model, array $input, bool $stream = false): array
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
                'stream' => $stream,
            ], fn ($value) => $value !== null),
            'responses' => array_filter([
                'model' => $model,
                'instructions' => $system === '' ? null : $system,
                'input' => $messages,
                'max_output_tokens' => $max,
                'temperature' => $temperature,
                'stream' => $stream,
            ], fn ($value) => $value !== null),
            'chat_completions' => array_filter([
                'model' => $model,
                'messages' => array_values(array_filter([
                    $system === '' ? null : ['role' => 'system', 'content' => $system],
                    ...$messages,
                ])),
                'max_tokens' => $max,
                'temperature' => $temperature,
                'stream' => $stream,
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
