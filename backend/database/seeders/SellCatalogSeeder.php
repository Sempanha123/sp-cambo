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
use RuntimeException;

class SellCatalogSeeder extends Seeder
{
    private const PROVIDER_SLUG = 'omniroute-primary';
    private const OPENAI_MODEL = 'OpenAI Codex';
    private const GEMINI_MODEL = 'Gemini Google AI Studio';

    /** @var array<string,array<string,mixed>> */
    private const MODELS = [
        'openai-codex' => [
            'internal_model_id' => self::OPENAI_MODEL,
            'display_name' => self::OPENAI_MODEL,
            'family' => 'codex',
            'reasoning' => true,
            'context_tokens' => 220000,
            'max_output_tokens' => 16384,
        ],
        'gemini-google-ai-studio' => [
            'internal_model_id' => self::GEMINI_MODEL,
            'display_name' => self::GEMINI_MODEL,
            'family' => 'gemini',
            'reasoning' => true,
            'context_tokens' => 1000000,
            'max_output_tokens' => 16384,
        ],
    ];

    /** @var list<string> */
    private const PACKAGE_SLUGS = [
        'openai-codex-10m',
        'openai-codex-50m',
        'gemini-google-ai-studio-10m',
        'gemini-google-ai-studio-50m',
        'multi-model-credit-10usd',
        'multi-model-credit-100usd',
    ];

    public function run(): void
    {
        $baseUrl = trim((string) config('services.spcambo.sell_catalog_base_url', ''));
        $token = trim((string) config('services.spcambo.sell_catalog_token', ''));
        $missing = collect([
            'ANTHROPIC_BASE_URL' => $baseUrl,
            'ANTHROPIC_AUTH_TOKEN' => $token,
        ])->filter(static fn (string $value): bool => $value === '')->keys()->all();

        if ($missing !== []) {
            throw new RuntimeException('Sell catalog seed requires private backend .env values: '.implode(', ', $missing).'.');
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

        $probeResults = [];
        foreach (self::MODELS as $publicAlias => $definition) {
            $internalModel = (string) $definition['internal_model_id'];
            $probeResults[$publicAlias] = app()->environment('testing')
                ? ['success' => true, 'endpoint_kind' => 'chat_completions', 'endpoint_kinds' => ['chat_completions', 'messages']]
                : app(ProviderProbeService::class)->probe($revision, $internalModel);
        }

        $routeReady = collect($probeResults)->contains(static fn (array $result): bool => (bool) ($result['success'] ?? false));
        $revision->forceFill([
            'lifecycle_status' => $routeReady ? ProviderConnectionRevision::STATUS_READY : ProviderConnectionRevision::STATUS_PENDING,
            'last_probe_status' => $routeReady ? 'SUCCESS' : 'FAILED',
            'last_probe_at' => now(),
        ])->save();

        if ($routeReady) {
            $provider->activateConnectionRevision($revision);
        }

        $aliasIds = [];
        $modelIds = [];
        foreach (self::MODELS as $publicAlias => $definition) {
            $modelReady = (bool) ($probeResults[$publicAlias]['success'] ?? false);
            $endpointKind = $modelReady ? ($probeResults[$publicAlias]['endpoint_kind'] ?? null) : null;
            $endpointKinds = $modelReady && is_array($probeResults[$publicAlias]['endpoint_kinds'] ?? null)
                ? $probeResults[$publicAlias]['endpoint_kinds']
                : (is_string($endpointKind) ? [$endpointKind] : []);
            $protocolCapabilities = $this->protocolCapabilities($endpointKinds);
            $model = AiModel::query()->firstOrNew([
                'provider_id' => $provider->id,
                'internal_model_id' => $definition['internal_model_id'],
            ]);
            $model->fill([
                'display_name' => $definition['display_name'],
                'family' => $definition['family'],
                'family_label' => $definition['display_name'],
                'capabilities' => [
                    'streaming' => true,
                    'tools' => true,
                    'vision' => $definition['family'] === 'gemini',
                    'reasoning' => (bool) $definition['reasoning'],
                    'context_tokens' => (int) $definition['context_tokens'],
                    'max_output_tokens' => (int) $definition['max_output_tokens'],
                ],
                'limits' => [
                    'context_window' => (int) $definition['context_tokens'],
                    'max_output_tokens' => (int) $definition['max_output_tokens'],
                ],
                'enabled' => $modelReady,
            ]);
            if ($model->commercial_resale_verified_at === null) {
                $model->commercial_resale_verified_at = now();
            }
            $model->save();
            $modelIds[] = $model->id;

            $alias = ModelAlias::query()->updateOrCreate(
                ['public_alias' => $publicAlias],
                [
                    'ai_model_id' => $model->id,
                    'display_name' => $definition['display_name'],
                    'description' => 'SP Cambo public model routed through the verified OmniRoute inference protocol.',
                    'capabilities' => [
                        ...$protocolCapabilities,
                        'streaming' => true,
                        'tools' => true,
                        'vision' => $definition['family'] === 'gemini',
                        'reasoning' => (bool) $definition['reasoning'],
                        'context_tokens' => (int) $definition['context_tokens'],
                        'max_output_tokens' => (int) $definition['max_output_tokens'],
                    ],
                    'limits' => [
                        'requests_per_minute' => 60,
                        'tokens_per_minute' => 200000,
                        'concurrency' => 4,
                        'context_tokens' => (int) $definition['context_tokens'],
                        'max_output_tokens' => (int) $definition['max_output_tokens'],
                    ],
                    'status' => 'active',
                    'enabled' => $modelReady,
                    'customer_visible' => $modelReady,
                ],
            );
            $aliasIds[$publicAlias] = $alias->id;

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
        }

        $publishedAliases = ModelAlias::query()->published()
            ->whereIn('public_alias', array_keys(self::MODELS))
            ->pluck('id', 'public_alias');

        $defaultPlaygroundAlias = $publishedAliases->has('openai-codex')
            ? 'openai-codex'
            : ($publishedAliases->keys()->first() ?: 'openai-codex');

        PlaygroundSetting::current()->forceFill([
            'enabled' => true,
            'daily_token_quota' => max(0, (int) config('services.spcambo.playground_daily_token_quota', 20000)),
            'max_output_tokens' => 16_384,
            'allowed_model_aliases' => array_keys(self::MODELS),
            'gateway_base_url' => rtrim((string) config('services.spcambo.gateway_base_url', 'http://127.0.0.1:3010'), '/'),
            'default_model_alias' => $defaultPlaygroundAlias,
            'allow_model_switching' => true,
        ])->save();

        foreach ($this->packageDefinitions() as $definition) {
            $requiredAliases = $definition['aliases'];
            unset($definition['aliases']);
            $sellable = collect($requiredAliases)->every(static fn (string $alias) => $publishedAliases->has($alias));

            $package = Package::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + [
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
                    'auto_creates_api_key' => true,
                    'minimum_margin_bps' => 0,
                    'profitability_override_reason' => 'Initial operator-approved two-model catalog; verify upstream costs before final production pricing.',
                    'enabled' => $sellable,
                    'customer_visible' => $sellable,
                ],
            );
            $package->modelAliases()->sync(collect($requiredAliases)->map(fn (string $alias) => $aliasIds[$alias])->all());
        }

        // The operator explicitly requested a clean two-model sell catalog. Keep
        // historical rows for accounting/audit integrity, but hide every provider,
        // model, alias and product that is not part of this catalog.
        Provider::query()->where('id', '!=', $provider->id)->update(['enabled' => false]);
        AiModel::query()->whereNotIn('id', $modelIds)->update(['enabled' => false]);
        ModelAlias::query()->whereNotIn('id', array_values($aliasIds))->update([
            'enabled' => false,
            'customer_visible' => false,
        ]);
        Package::query()->whereNotIn('slug', self::PACKAGE_SLUGS)->update([
            'enabled' => false,
            'customer_visible' => false,
        ]);

        if ($this->command) {
            $this->command->line('Sell catalog provider: OmniRoute');
            foreach (self::MODELS as $publicAlias => $definition) {
                $ready = (bool) ($probeResults[$publicAlias]['success'] ?? false);
                $endpointKind = $ready ? (string) ($probeResults[$publicAlias]['endpoint_kind'] ?? 'unknown') : 'none';
                $verified = $ready && is_array($probeResults[$publicAlias]['endpoint_kinds'] ?? null)
                    ? implode(',', $probeResults[$publicAlias]['endpoint_kinds'])
                    : $endpointKind;
                $this->command->line($definition['display_name'].': '.($ready ? 'READY' : 'NOT READY').' -> '.$publicAlias.' · preferred '.$endpointKind.' · verified '.$verified);
            }
            $this->command->line('Products: OpenAI 10M/50M, Gemini 10M/50M, shared $10/$100 credit');
            if ($publishedAliases->count() !== 2) {
                $this->command->warn('Only verified models are published. Fix the unavailable OmniRoute model and rerun this seeder.');
            }
        }
    }

    /** @param list<string> $endpointKinds @return array{messages_api:bool,responses_api:bool,chat_completions_api:bool,playground_protocol:string|null} */
    private function protocolCapabilities(array $endpointKinds): array
    {
        $endpointKinds = array_values(array_unique(array_filter(
            $endpointKinds,
            static fn ($value): bool => is_string($value) && in_array($value, ['messages', 'responses', 'chat_completions'], true)
        )));

        // The hosted Playground prefers Chat Completions because OmniRoute emits
        // standard incremental deltas plus final usage there. Public API clients
        // still retain every other protocol that the exact custom model verified.
        $playgroundProtocol = in_array('chat_completions', $endpointKinds, true)
            ? 'chat_completions'
            : (in_array('messages', $endpointKinds, true)
                ? 'messages'
                : (in_array('responses', $endpointKinds, true) ? 'responses' : null));

        return [
            'messages_api' => in_array('messages', $endpointKinds, true),
            'responses_api' => in_array('responses', $endpointKinds, true),
            'chat_completions_api' => in_array('chat_completions', $endpointKinds, true),
            'playground_protocol' => $playgroundProtocol,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function packageDefinitions(): array
    {
        return [
            $this->tokenPackage('openai-codex-10m', 'OpenAI Codex 10M Tokens', 'OpenAI Codex', 10_000_000, 100, 10, ['openai-codex'], false),
            $this->tokenPackage('openai-codex-50m', 'OpenAI Codex 50M Tokens', 'OpenAI Codex', 50_000_000, 500, 20, ['openai-codex'], true),
            $this->tokenPackage('gemini-google-ai-studio-10m', 'Gemini Google AI Studio 10M Tokens', 'Gemini Google AI Studio', 10_000_000, 100, 30, ['gemini-google-ai-studio'], false),
            $this->tokenPackage('gemini-google-ai-studio-50m', 'Gemini Google AI Studio 50M Tokens', 'Gemini Google AI Studio', 50_000_000, 500, 40, ['gemini-google-ai-studio'], true),
            [
                'slug' => 'multi-model-credit-10usd',
                'name' => '$10 Multi-Model Credit',
                'subtitle' => '$10.00 credit usable with OpenAI Codex and Gemini Google AI Studio.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                'family' => 'multi-model',
                'family_label' => 'OpenAI Codex + Gemini Google AI Studio',
                'advertised_units' => 1_000,
                'unit_label' => 'USD credit',
                'price_minor' => 1_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 50,
                'aliases' => ['openai-codex', 'gemini-google-ai-studio'],
            ],
            [
                'slug' => 'multi-model-credit-100usd',
                'name' => '$100 Multi-Model Credit',
                'subtitle' => '$100.00 credit usable with OpenAI Codex and Gemini Google AI Studio.',
                'badge' => 'Credit',
                'billing_mode' => 'CREDIT_BALANCE',
                'family' => 'multi-model',
                'family_label' => 'OpenAI Codex + Gemini Google AI Studio',
                'advertised_units' => 10_000,
                'unit_label' => 'USD credit',
                'price_minor' => 10_000,
                'currency_exponent' => 2,
                'featured' => false,
                'sort_order' => 60,
                'aliases' => ['openai-codex', 'gemini-google-ai-studio'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function tokenPackage(string $slug, string $name, string $familyLabel, int $units, int $priceMinor, int $sortOrder, array $aliases, bool $featured): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'subtitle' => number_format($units).' metered tokens for Playground or one API key.',
            'badge' => $featured ? 'Popular' : 'Starter',
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => str_contains(strtolower($familyLabel), 'gemini') ? 'gemini' : 'codex',
            'family_label' => $familyLabel,
            'advertised_units' => $units,
            'unit_label' => 'tokens',
            'price_minor' => $priceMinor,
            'currency_exponent' => 2,
            'featured' => $featured,
            'sort_order' => $sortOrder,
            'aliases' => $aliases,
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
