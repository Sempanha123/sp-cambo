<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\ProviderProbeService;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $baseUrl = trim((string) config('services.spcambo.demo_upstream_base_url', 'http://127.0.0.1:20128/v1'));
        $token = trim((string) config('services.spcambo.demo_upstream_token', ''));
        $internalModel = trim((string) config('services.spcambo.demo_upstream_model', 'OpenAI Codex'));
        $publicAlias = trim((string) config('services.spcambo.demo_public_alias', 'openai-codex'));
        $protocols = $this->protocols((string) config('services.spcambo.demo_protocols', 'messages'));

        if ($internalModel === '') {
            $internalModel = 'OpenAI Codex';
        }
        if ($publicAlias === '') {
            $publicAlias = 'openai-codex';
        }

        // The gateway appends /v1/messages, /v1/responses or /v1/chat/completions,
        // so store an origin root even when the operator supplied an Anthropic-
        // style base URL ending in /v1.
        $origin = $this->originRoot($baseUrl);
        $provider = Provider::query()->updateOrCreate(
            ['slug' => 'local-demo-upstream'],
            ['name' => 'OmniRoute Local', 'enabled' => true],
        );

        $credential = $token !== '' ? $token : 'demo-token-not-configured';
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->where('origin', $origin)
            ->where('connection_type', 'omniroute')
            ->whereIn('lifecycle_status', [
                ProviderConnectionRevision::STATUS_PENDING,
                ProviderConnectionRevision::STATUS_READY,
            ])
            ->get()
            ->first(fn (ProviderConnectionRevision $candidate): bool => hash_equals((string) $candidate->credential, $credential));

        if (! $revision) {
            $revision = ProviderConnectionRevision::query()->create([
                'provider_id' => $provider->id,
                'route_version' => ((int) ProviderConnectionRevision::query()->where('provider_id', $provider->id)->max('route_version')) + 1,
                'origin' => $origin,
                'connection_type' => 'omniroute',
                'credential' => $credential,
                'credential_suffix' => $token === '' ? null : $this->credentialSuffix($token),
                'timeout_ms' => 60000,
                'policy_version' => 1,
                'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
            ]);
        }

        $probeSucceeded = $revision->isRouteReady();
        $probeAttempted = false;
        if (app()->environment('testing')) {
            // Unit/feature tests must not depend on a developer machine service.
            $probeSucceeded = true;
        } elseif ((bool) config('services.spcambo.demo_probe_on_seed', true) && $token !== '') {
            $probeAttempted = true;
            $result = app(ProviderProbeService::class)->probe($revision, $internalModel);
            $probeSucceeded = $result['success'];
        }

        if ($probeSucceeded) {
            $revision->forceFill([
                'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
                'last_probe_status' => 'SUCCESS',
                'last_probe_at' => now(),
            ])->save();
            $provider->activateConnectionRevision($revision);
        } elseif ($probeAttempted || $token === '') {
            // Do not fake READY when the real local upstream cannot be verified.
            // An already-READY route is not automatically demoted by a transient
            // seed-time outage, matching the normal admin probe lifecycle.
            $revision->forceFill([
                'last_probe_status' => $token === '' ? null : 'FAILED',
                'last_probe_at' => $token === '' ? null : now(),
            ])->save();
        }

        $model = AiModel::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'internal_model_id' => $internalModel],
            [
                'display_name' => $internalModel,
                'family' => 'codex',
                'family_label' => 'OpenAI Codex',
                'capabilities' => [
                    'streaming' => true,
                    'tools' => true,
                    'vision' => false,
                    'reasoning' => true,
                    'context_tokens' => 220000,
                    'max_output_tokens' => 16384,
                ],
                'limits' => [
                    'context_window' => 220000,
                    'max_output_tokens' => 16384,
                ],
                'commercial_resale_verified_at' => now(),
                'enabled' => true,
            ],
        );

        $alias = ModelAlias::query()->updateOrCreate(
            ['public_alias' => $publicAlias],
            [
                'ai_model_id' => $model->id,
                'display_name' => $internalModel,
                'description' => 'SP Cambo acceptance model routed through the operator-configured OmniRoute instance.',
                'capabilities' => [
                    'messages_api' => in_array('messages', $protocols, true),
                    'responses_api' => in_array('responses', $protocols, true),
                    'chat_completions_api' => in_array('chat_completions', $protocols, true),
                    'streaming' => true,
                    'tools' => true,
                    'vision' => false,
                    'reasoning' => true,
                    'context_tokens' => 220000,
                    'max_output_tokens' => 16384,
                ],
                'limits' => [
                    'requests_per_minute' => 60,
                    'tokens_per_minute' => 200000,
                    'concurrency' => 4,
                    'context_tokens' => 220000,
                    'max_output_tokens' => 16384,
                ],
                'status' => 'active',
                'enabled' => true,
                'customer_visible' => true,
            ],
        );

        // Demo sell-pricing is deliberately editable. Upstream cost fields stay
        // unknown rather than inventing a provider cost; demo packages carry an
        // explicit operator-test override so publication remains honest.
        ModelPricing::query()->updateOrCreate(
            ['model_alias_id' => $alias->id],
            [
                'currency' => 'USD',
                'exponent' => 2,
                'input_per_million_minor' => 100,
                'output_per_million_minor' => 400,
                'cache_read_per_million_minor' => 25,
                'cache_write_per_million_minor' => 100,
                'reasoning_per_million_minor' => 400,
                'upstream_input_per_million_minor' => null,
                'upstream_output_per_million_minor' => null,
                'upstream_cache_read_per_million_minor' => null,
                'upstream_cache_write_per_million_minor' => null,
                'upstream_reasoning_per_million_minor' => null,
                'upstream_cost_verified_at' => null,
            ],
        );

        if ($this->command) {
            if ($token === '') {
                $this->command->warn('OmniRoute API key is not configured. Set OMNIROUTE_API_KEY (or SP_CAMBO_DEMO_UPSTREAM_TOKEN for an isolated override), then reseed/probe.');
            } elseif ($probeSucceeded) {
                $this->command->info("OmniRoute model verified: {$publicAlias} -> {$internalModel}.");
            } else {
                $this->command->warn('OmniRoute model could not be verified. The route remains non-publishable; check OmniRoute, the configured model name, and the private API key.');
            }
        }
    }

    /** @return list<string> */
    private function protocols(string $value): array
    {
        $allowed = ['messages', 'responses', 'chat_completions'];

        return collect(explode(',', strtolower($value)))
            ->map(fn (string $protocol): string => trim($protocol))
            ->filter(fn (string $protocol): bool => in_array($protocol, $allowed, true))
            ->unique()
            ->values()
            ->all() ?: ['messages'];
    }

    private function originRoot(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl !== '' ? $baseUrl : 'http://127.0.0.1:20128/v1', '/');

        return str_ends_with(strtolower($baseUrl), '/v1')
            ? substr($baseUrl, 0, -3)
            : $baseUrl;
    }

    private function credentialSuffix(string $credential): ?string
    {
        $suffix = substr($credential, -4);

        return ctype_alnum($suffix) ? $suffix : null;
    }
}
