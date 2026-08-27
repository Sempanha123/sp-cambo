<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\ProviderProbeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class FirstSellSeeder extends Seeder
{
    private const PROVIDER_SLUG = 'omniroute-primary';

    /** @var list<string> */
    private const PACKAGE_SLUGS = [
        'openai-codex-10m',
        'openai-codex-50m',
        'openai-codex-credit-10usd',
        'openai-codex-credit-100usd',
    ];

    /** @var list<string> */
    private const LEGACY_DEMO_PACKAGE_SLUGS = [
        'demo-token-10m',
        'demo-token-50m',
        'demo-credit-10usd',
        'demo-credit-100usd',
    ];

    public function run(): void
    {
        $baseUrl = trim((string) config('services.spcambo.first_sell_base_url', ''));
        $token = trim((string) config('services.spcambo.first_sell_token', ''));
        $internalModel = trim((string) config('services.spcambo.first_sell_model', ''));

        $missing = collect([
            'ANTHROPIC_BASE_URL' => $baseUrl,
            'ANTHROPIC_AUTH_TOKEN' => $token,
            'ANTHROPIC_MODEL' => $internalModel,
        ])->filter(static fn (string $value): bool => $value === '')->keys()->all();

        if ($missing !== []) {
            throw new RuntimeException('First sell seed requires private backend .env values: '.implode(', ', $missing).'.');
        }

        $publicAlias = substr(Str::slug($internalModel), 0, 100);
        if ($publicAlias === '') {
            throw new RuntimeException('ANTHROPIC_MODEL could not be converted to a safe public model alias.');
        }

        $origin = $this->originRoot($baseUrl);
        $provider = Provider::query()->updateOrCreate(
            ['slug' => self::PROVIDER_SLUG],
            ['name' => 'OmniRoute', 'enabled' => true],
        );

        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->where('origin', $origin)
            ->where('connection_type', 'omniroute')
            ->whereIn('lifecycle_status', [
                ProviderConnectionRevision::STATUS_PENDING,
                ProviderConnectionRevision::STATUS_READY,
            ])
            ->get()
            ->first(static fn (ProviderConnectionRevision $candidate): bool => hash_equals((string) $candidate->credential, $token));

        if (! $revision) {
            $revision = ProviderConnectionRevision::query()->create([
                'provider_id' => $provider->id,
                'route_version' => ((int) ProviderConnectionRevision::query()->where('provider_id', $provider->id)->max('route_version')) + 1,
                'origin' => $origin,
                'connection_type' => 'omniroute',
                'credential' => $token,
                'credential_suffix' => $this->credentialSuffix($token),
                'timeout_ms' => 60000,
                'policy_version' => 1,
                'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
            ]);
        }

        // Feature tests must not depend on an operator's local OmniRoute process.
        $probeSucceeded = app()->environment('testing')
            ? true
            : app(ProviderProbeService::class)->probe($revision, $internalModel)['success'];

        if ($probeSucceeded) {
            $revision->forceFill([
                'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
                'last_probe_status' => 'SUCCESS',
                'last_probe_at' => now(),
            ])->save();
            $provider->activateConnectionRevision($revision);
        } else {
            $revision->forceFill([
                'last_probe_status' => 'FAILED',
                'last_probe_at' => now(),
            ])->save();
        }

        $model = AiModel::query()->firstOrNew([
            'provider_id' => $provider->id,
            'internal_model_id' => $internalModel,
        ]);
        $model->fill([
            'display_name' => $internalModel,
            'family' => 'codex',
            'family_label' => $internalModel,
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
            'enabled' => true,
        ]);
        // Running this explicit sell-catalog seeder is the operator's approval to
        // expose this model commercially. Never infer that approval from discovery.
        if ($model->commercial_resale_verified_at === null) {
            $model->commercial_resale_verified_at = now();
        }
        $model->save();

        $alias = ModelAlias::query()->updateOrCreate(
            ['public_alias' => $publicAlias],
            [
                'ai_model_id' => $model->id,
                'display_name' => $internalModel,
                'description' => 'SP Cambo public model routed through the configured OmniRoute-compatible Anthropic endpoint.',
                'capabilities' => [
                    'messages_api' => true,
                    'responses_api' => false,
                    'chat_completions_api' => false,
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
                // No upstream cost is invented by the seed. The initial catalog
                // carries an explicit operator override and remains editable.
                'upstream_input_per_million_minor' => null,
                'upstream_output_per_million_minor' => null,
                'upstream_cache_read_per_million_minor' => null,
                'upstream_cache_write_per_million_minor' => null,
                'upstream_reasoning_per_million_minor' => null,
                'upstream_cost_verified_at' => null,
            ],
        );

        PlaygroundSetting::current()->forceFill([
            'enabled' => true,
            'daily_token_quota' => max(0, (int) config('services.spcambo.playground_daily_token_quota', 20000)),
            'max_output_tokens' => 16_384,
            'allowed_model_aliases' => [$publicAlias],
            'gateway_base_url' => rtrim((string) config('services.spcambo.gateway_base_url', 'http://127.0.0.1:3010'), '/'),
            'default_model_alias' => $publicAlias,
            'allow_model_switching' => true,
        ])->save();

        $sellable = ModelAlias::query()->published()->whereKey($alias->id)->exists();
        foreach ($this->packageDefinitions($internalModel) as $definition) {
            $package = Package::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + [
                    'family' => 'codex',
                    'family_label' => $internalModel,
                    'currency' => 'USD',
                    'duration_seconds' => 30 * 24 * 60 * 60,
                    'limits' => [
                        'requests_per_minute' => 60,
                        'tokens_per_minute' => 200000,
                        'concurrency' => 4,
                        'max_request_bytes' => 1048576,
                        'max_output_tokens' => 16384,
                    ],
                    'billing_rules' => [],
                    // Website: customer chooses Playground / new key / existing
                    // key. Telegram: fulfillment creates a dedicated new key.
                    'auto_creates_api_key' => true,
                    'minimum_margin_bps' => 0,
                    'profitability_override_reason' => 'Initial operator-approved OpenAI Codex catalog; verify upstream costs before final production pricing.',
                    'enabled' => $sellable,
                    'customer_visible' => $sellable,
                ],
            );
            $package->modelAliases()->sync([$alias->id]);
        }

        // Retire only known old acceptance-fixture rows. Never delete them: old
        // orders/entitlements may still reference those historical records.
        Package::query()->whereIn('slug', self::LEGACY_DEMO_PACKAGE_SLUGS)->update([
            'enabled' => false,
            'customer_visible' => false,
        ]);
        Provider::query()->where('slug', 'local-demo-upstream')->update(['enabled' => false]);

        if ($this->command) {
            $this->command->line("First sell model: {$publicAlias} -> {$internalModel}");
            $this->command->line('Provider route: '.($probeSucceeded ? 'READY' : 'NOT READY'));
            $this->command->line('Products: 10M tokens, 50M tokens, $10 credit, $100 credit');
            if (! $sellable) {
                $this->command->warn('Products were seeded but remain hidden because the exact configured model could not be verified.');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function packageDefinitions(string $model): array
    {
        return [
            [
                'slug' => 'openai-codex-10m',
                'name' => $model.' 10M Tokens',
                'subtitle' => '10 million metered tokens for Playground or one API key.',
                'badge' => 'Starter',
                'billing_mode' => 'TOKEN_QUOTA',
                'advertised_units' => 10_000_000,
                'unit_label' => 'tokens',
                'price_minor' => 100,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 10,
            ],
            [
                'slug' => 'openai-codex-50m',
                'name' => $model.' 50M Tokens',
                'subtitle' => '50 million metered tokens for longer usage.',
                'badge' => 'Popular',
                'billing_mode' => 'TOKEN_QUOTA',
                'advertised_units' => 50_000_000,
                'unit_label' => 'tokens',
                'price_minor' => 500,
                'currency_exponent' => 2,
                'featured' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'openai-codex-credit-10usd',
                'name' => '$10 '.$model.' Credit',
                'subtitle' => '$10.00 of model usage credit.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                'advertised_units' => 1_000,
                'unit_label' => 'USD credit',
                'price_minor' => 1_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 30,
            ],
            [
                'slug' => 'openai-codex-credit-100usd',
                'name' => '$100 '.$model.' Credit',
                'subtitle' => '$100.00 of model usage credit.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                'advertised_units' => 10_000,
                'unit_label' => 'USD credit',
                'price_minor' => 10_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 40,
            ],
        ];
    }

    private function originRoot(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

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
