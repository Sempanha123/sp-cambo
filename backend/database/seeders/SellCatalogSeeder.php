<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\ReferralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SellCatalogSeeder extends Seeder
{
    private const PROVIDER_SLUG = 'omniroute-primary';
    private const DAY = 86_400;
    private const LONG_CREDIT_VALIDITY = 365 * self::DAY;
    private const SP_CREDIT_UNITS = 100_000; // $1 displayed Credit = 100k locally metered Tokens.
    private const PACKAGE_MINIMUM_MARGIN_BPS = 1_000;
    private const PACKAGE_PRICE_MARKUP_BPS = 1_000; // Small +10% retail increase over V20.6.

    /**
     * R44 LOW-PRICE VOLUME CURVES:
     *
     * Prices are SP Cambo package prices, not provider list prices. Each family has
     * one easy-to-review anchor and a decreasing multiplier for larger bundles.
     * priceFromProfile() performs integer-only calculation, applies the same private
     * reference-floor margin rule as the website, and rounds to a familiar x.x9
     * price. assertPriceProfile() prevents an accidental unit-price increase.
     *
     * @var array<string,array{label:string,sort_order:int,anchor_units:int,anchor_price_minor:int,multipliers_bps:array<int,int>}>
     */
    private const TOKEN_PRICE_PROFILES = [
        'claude' => [
            'label' => 'Claude',
            'sort_order' => 10,
            'anchor_units' => 100,
            'anchor_price_minor' => 159,
            'multipliers_bps' => [
                10 => 18_000,
                50 => 11_200,
                100 => 10_000,
                200 => 9_500,
                300 => 9_300,
                400 => 9_200,
                500 => 9_000,
                1000 => 8_000,
            ],
        ],
        'codex' => [
            'label' => 'Codex',
            'sort_order' => 30,
            'anchor_units' => 100,
            'anchor_price_minor' => 249,
            'multipliers_bps' => [
                10 => 10_000,
                50 => 10_000,
                100 => 10_000,
                200 => 9_650,
                300 => 9_300,
                500 => 9_050,
                1000 => 8_670,
            ],
        ],
        'gemini' => [
            'label' => 'Gemini',
            'sort_order' => 50,
            'anchor_units' => 100,
            'anchor_price_minor' => 119,
            'multipliers_bps' => [
                10 => 18_000,
                50 => 11_500,
                100 => 10_000,
                200 => 9_200,
                300 => 8_800,
                500 => 8_500,
                1000 => 7_250,
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'sort_order' => 60,
            'anchor_units' => 100,
            'anchor_price_minor' => 79,
            'multipliers_bps' => [
                10 => 16_000,
                50 => 11_000,
                100 => 10_000,
                200 => 9_200,
                300 => 8_800,
                500 => 8_500,
                1000 => 7_500,
            ],
        ],
    ];

    /** @var array<string,array{label:string,sort_order:int,anchor_units:int,anchor_price_minor:int,multipliers_bps:array<int,int>}> */
    private const CREDIT_PRICE_PROFILES = [
        'claude' => [
            'label' => 'Claude',
            'sort_order' => 70,
            'anchor_units' => 100,
            'anchor_price_minor' => 149,
            'multipliers_bps' => [
                50 => 11_200,
                100 => 10_000,
                200 => 9_100,
                500 => 7_950,
                1000 => 6_990,
                2000 => 5_620,
                3000 => 4_810,
            ],
        ],
        'codex' => [
            'label' => 'Codex',
            'sort_order' => 90,
            'anchor_units' => 100,
            'anchor_price_minor' => 249,
            'multipliers_bps' => [
                50 => 11_000,
                100 => 10_000,
                200 => 8_950,
                500 => 8_000,
                1000 => 7_000,
                2000 => 6_010,
                3000 => 5_340,
            ],
        ],
        'gemini' => [
            'label' => 'Gemini',
            'sort_order' => 110,
            'anchor_units' => 100,
            'anchor_price_minor' => 99,
            'multipliers_bps' => [
                50 => 11_000,
                100 => 10_000,
                200 => 9_200,
                500 => 8_600,
                1000 => 7_350,
                2000 => 6_680,
                3000 => 6_250,
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'sort_order' => 130,
            'anchor_units' => 100,
            'anchor_price_minor' => 59,
            'multipliers_bps' => [
                50 => 11_000,
                100 => 10_000,
                200 => 9_200,
                500 => 8_500,
                1000 => 7_400,
                2000 => 6_700,
                3000 => 6_200,
            ],
        ],
    ];

    /**
     * R45 follows the operator-owned OmniRoute route names configured in production. Public aliases are
     * just SP Cambo routing labels and several aliases may point at the same combo.
     * That lets OmniRoute rotate/fail over accounts without changing customer keys.
     *
     * R42 LOCAL CACHE-AWARE METERING:
     * Customer settlement is measured only at the SP Cambo public edge. New input
     * and generated output consume 1 Token each. A repeated prompt prefix detected
     * by SP Cambo's own hash-only local cache consumes 0.25 Token per cached Token.
     * OmniRoute/provider usage, cache, reasoning and cost counters never participate.
     */
    private const ROUTES = [
        'claude' => [
            'internal_model_id' => 'Claude',
            'internal_display_name' => 'Claude Combo',
            'family' => 'claude',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_000_000,
            'max_output_tokens' => 128_000,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                'opus-5' => [
                    'display_name' => 'Claude Opus 5',
                    'context_tokens' => 1_000_000,
                    'max_output_tokens' => 128_000,
                    'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
                ],
                'sonnet-5' => [
                    'display_name' => 'Claude Sonnet 5',
                    'context_tokens' => 1_000_000,
                    'max_output_tokens' => 128_000,
                    'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
                ],
                'haiku-4.5' => [
                    'display_name' => 'Claude Haiku 4.5',
                    'context_tokens' => 200_000,
                    'max_output_tokens' => 64_000,
                    'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
                ],
            ],
            // Aggressive SP-local customer rates, USD exponent-3 units / 1M SP-metered tokens.
            'sell' => [
                'input' => 25,        // $0.025
                'output' => 125,      // $0.125
                'cache_read' => 6,    // $0.006 local cached input
                'cache_write' => 30,  // $0.030
                'reasoning' => 125,   // $0.125
            ],
            // SP internal reference floor for private margin reporting only.
            // It is NOT read from OmniRoute and does not represent a provider invoice.
            'reference' => [
                'input' => 4,
                'output' => 20,
                'cache_read' => 1,
                'cache_write' => 5,
                'reasoning' => 20,
            ],
            'weights' => [
                'input' => 1_000_000,
                'output' => 1_000_000,
                'cache_read' => 250_000,
                'cache_write' => 1_000_000,
                'reasoning' => 1_000_000,
            ],
            'billing_multipliers_bps' => [
                'input' => 10_000,
                'output' => 10_000,
                'cache_read' => 10_000,
                'cache_write' => 10_000,
                'reasoning' => 10_000,
            ],
        ],
        'codex' => [
            'internal_model_id' => 'Chatgpt',
            'internal_display_name' => 'OpenAI Codex Combo',
            'family' => 'codex',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_050_000,
            'max_output_tokens' => 128_000,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                '5.6-sol' => [
                    'display_name' => 'GPT-5.6 Sol',
                    'context_tokens' => 1_050_000,
                    'max_output_tokens' => 128_000,
                    'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
                ],
                '4.8-sol' => [
                    'display_name' => 'GPT-4.8 Sol',
                    'context_tokens' => 400_000,
                    'max_output_tokens' => 128_000,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
                'openai-codex' => [
                    'display_name' => 'OpenAI Codex',
                    'context_tokens' => 400_000,
                    'max_output_tokens' => 128_000,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
            ],
            'sell' => [
                'input' => 75,        // $0.075
                'output' => 375,      // $0.375
                'cache_read' => 19,   // $0.019 local cached input
                'cache_write' => 90,  // $0.090
                'reasoning' => 375,   // $0.375
            ],
            'reference' => [
                'input' => 6,
                'output' => 30,
                'cache_read' => 1,
                'cache_write' => 8,
                'reasoning' => 30,
            ],
            'weights' => [
                'input' => 1_000_000,
                'output' => 1_000_000,
                'cache_read' => 250_000,
                'cache_write' => 1_000_000,
                'reasoning' => 1_000_000,
            ],
            'billing_multipliers_bps' => [
                'input' => 10_000,
                'output' => 10_000,
                'cache_read' => 10_000,
                'cache_write' => 10_000,
                'reasoning' => 10_000,
            ],
        ],
        'gemini' => [
            'internal_model_id' => 'Gemini',
            'internal_display_name' => 'Gemini Google AI Studio Combo',
            'family' => 'gemini',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_048_576,
            'max_output_tokens' => 65_536,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                'gemini-3.6-flash' => [
                    'display_name' => 'Gemini 3.6 Flash',
                    'context_tokens' => 1_048_576,
                    'max_output_tokens' => 65_536,
                    'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
                ],
                'gemini-3.6-pro' => [
                    'display_name' => 'Gemini 3.6 Pro',
                    'context_tokens' => 1_048_576,
                    'max_output_tokens' => 65_536,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
                'gemini-google-ai-studio' => [
                    'display_name' => 'Gemini Google AI Studio',
                    'context_tokens' => 1_048_576,
                    'max_output_tokens' => 65_536,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
            ],
            'sell' => [
                'input' => 18,        // $0.018
                'output' => 90,       // $0.090
                'cache_read' => 5,    // $0.005 local cached input
                'cache_write' => 18,  // $0.018
                'reasoning' => 90,    // $0.090
            ],
            'reference' => [
                'input' => 3,
                'output' => 15,
                'cache_read' => 1,
                'cache_write' => 4,
                'reasoning' => 15,
            ],
            'weights' => [
                'input' => 1_000_000,
                'output' => 1_000_000,
                'cache_read' => 250_000,
                'cache_write' => 1_000_000,
                'reasoning' => 1_000_000,
            ],
            'billing_multipliers_bps' => [
                'input' => 10_000,
                'output' => 10_000,
                'cache_read' => 10_000,
                'cache_write' => 10_000,
                'reasoning' => 10_000,
            ],
        ],
        'deepseek' => [
            // Operator-provided OmniRoute internal model id. Keep this exact spelling.
            'internal_model_id' => 'Deepseek',
            'internal_display_name' => 'DeepSeek Combo',
            'family' => 'deepseek',
            'vision' => false,
            'reasoning' => true,
            'context_tokens' => 128_000,
            'max_output_tokens' => 65_536,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                'deepseek-v4-flash' => [
                    'display_name' => 'DeepSeek V4 Flash',
                    'context_tokens' => 128_000,
                    'max_output_tokens' => 65_536,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
                'deepseek-v4-pro' => [
                    'display_name' => 'DeepSeek V4 Pro',
                    'context_tokens' => 128_000,
                    'max_output_tokens' => 65_536,
                    'capability_basis' => 'SP_CAMBO_ROUTE_PROFILE',
                ],
            ],
            'sell' => [
                'input' => 10,        // $0.010
                'output' => 50,       // $0.050
                'cache_read' => 3,    // $0.003 local cached input
                'cache_write' => 12,  // $0.012
                'reasoning' => 50,    // $0.050
            ],
            'reference' => [
                'input' => 4,
                'output' => 10,
                'cache_read' => 1,
                'cache_write' => 4,
                'reasoning' => 10,
            ],
            'weights' => [
                'input' => 1_000_000,
                'output' => 1_000_000,
                'cache_read' => 250_000,
                'cache_write' => 1_000_000,
                'reasoning' => 1_000_000,
            ],
            'billing_multipliers_bps' => [
                'input' => 10_000,
                'output' => 10_000,
                'cache_read' => 10_000,
                'cache_write' => 10_000,
                'reasoning' => 10_000,
            ],
        ],
    ];

    public function run(): void
    {
        $provider = Provider::query()->updateOrCreate(
            ['slug' => self::PROVIDER_SLUG],
            ['name' => 'OmniRoute', 'enabled' => true],
        );

        $bootstrapRevision = $this->seedLocalOmniRouteBootstrap($provider);
        $modelIds = [];
        $aliasIds = [];
        $routeAliases = [];

        foreach (self::ROUTES as $routeKey => $route) {
            $model = AiModel::query()->firstOrNew([
                'provider_id' => $provider->id,
                'internal_model_id' => $route['internal_model_id'],
            ]);
            $modelWasNew = ! $model->exists;
            $model->forceFill([
                'display_name' => $route['internal_display_name'],
                'family' => $route['family'],
                'family_label' => $route['internal_display_name'],
                'capabilities' => [
                    'streaming' => true,
                    'tools' => true,
                    'vision' => (bool) $route['vision'],
                    'reasoning' => (bool) $route['reasoning'],
                    'context_tokens' => (int) $route['context_tokens'],
                    'max_output_tokens' => (int) $route['max_output_tokens'],
                ],
                'limits' => [
                    'context_window' => (int) $route['context_tokens'],
                    'max_output_tokens' => (int) $route['max_output_tokens'],
                ],
                'enabled' => true,
            ]);
            if ($modelWasNew && $model->commercial_resale_verified_at === null) {
                $model->commercial_resale_verified_at = now();
            }
            $model->save();
            $modelIds[] = $model->id;

            $routeAliases[$routeKey] = [];
            foreach ($route['aliases'] as $publicAlias => $aliasProfile) {
                $displayName = (string) $aliasProfile['display_name'];
                $contextTokens = (int) $aliasProfile['context_tokens'];
                $maxOutputTokens = (int) $aliasProfile['max_output_tokens'];
                $capabilityBasis = (string) $aliasProfile['capability_basis'];
                $description = $capabilityBasis === 'PROVIDER_PUBLIC_SPEC'
                    ? 'SP Cambo routing alias. Capability window values follow the provider-published model specification; package and service limits may be lower. Routing and prices are operated by SP Cambo and are not provider list prices.'
                    : 'SP Cambo-specific routing alias for the operator-configured combo. Capability values are an SP Cambo route profile, not a provider-published model specification. Routing and prices are operated by SP Cambo.';

                $alias = ModelAlias::query()->firstOrNew(['public_alias' => $publicAlias]);
                $alias->forceFill([
                    'ai_model_id' => $model->id,
                    'display_name' => $displayName,
                    'description' => $description,
                    'status' => 'active',
                    'enabled' => true,
                    'customer_visible' => true,
                    'capabilities' => [
                        'messages_api' => true,
                        'responses_api' => true,
                        'chat_completions_api' => true,
                        'playground_protocol' => 'chat_completions',
                        'streaming' => true,
                        'tools' => true,
                        'vision' => (bool) $route['vision'],
                        'reasoning' => (bool) $route['reasoning'],
                        'context_tokens' => $contextTokens,
                        'max_output_tokens' => $maxOutputTokens,
                        'capability_basis' => $capabilityBasis,
                    ],
                    'limits' => $this->customerLimits($maxOutputTokens) + [
                        'context_tokens' => $contextTokens,
                        'billing_unit_label' => 'Tokens',
                        'billing_multipliers_bps' => $route['billing_multipliers_bps'],
                        'billing_usage_classes' => ['input', 'output', 'cache_read'],
                        'minimum_request_units' => (int) $route['minimum_request_units'],
                        'local_cache_read_billing_bps' => (int) $route['local_cache_read_billing_bps'],
                        'sp_credit_billable_units' => self::SP_CREDIT_UNITS,
                        'metering_method' => 'LOCAL_CACHE_AWARE_V1',
                        'sp_routing_alias' => true,
                    ],
                ])->save();

                $aliasIds[$publicAlias] = $alias->id;
                $routeAliases[$routeKey][] = $publicAlias;

                $sell = $route['sell'];
                $reference = $route['reference'];
                $pricing = ModelPricing::query()->firstOrNew(['model_alias_id' => $alias->id]);
                $pricing->forceFill([
                    'currency' => 'USD',
                    'exponent' => 3,
                    'input_per_million_minor' => $sell['input'],
                    'output_per_million_minor' => $sell['output'],
                    'cache_read_per_million_minor' => $sell['cache_read'],
                    'cache_write_per_million_minor' => $sell['cache_write'],
                    'reasoning_per_million_minor' => $sell['reasoning'],
                    // Compatibility columns: these are SP-local private reference floors,
                    // not provider/OmniRoute reported costs.
                    'upstream_input_per_million_minor' => $reference['input'],
                    'upstream_output_per_million_minor' => $reference['output'],
                    'upstream_cache_read_per_million_minor' => $reference['cache_read'],
                    'upstream_cache_write_per_million_minor' => $reference['cache_write'],
                    'upstream_reasoning_per_million_minor' => $reference['reasoning'],
                    'upstream_cost_verified_at' => now(),
                ])->save();

                $this->assertLocalReferenceMargin($publicAlias, $sell, $reference, self::PACKAGE_MINIMUM_MARGIN_BPS);
            }
        }

        $allPublicAliases = collect($routeAliases)->flatten()->values()->all();

        PlaygroundSetting::current()->forceFill([
            'enabled' => true,
            'daily_token_quota' => 200_000,
            'max_output_tokens' => 65_536,
            'allowed_model_aliases' => $allPublicAliases,
            'gateway_base_url' => rtrim((string) config('services.spcambo.gateway_base_url', 'http://127.0.0.1:3010'), '/'),
            'default_model_alias' => 'gemini-3.6-flash',
            'allow_model_switching' => true,
        ])->save();

        ReferralSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enabled' => true,
                'registration_reward_enabled' => true,
                'registration_reward_started_at' => now(),
                'registration_reward_mode' => 'CREDIT_BALANCE',
                'registration_credit_minor' => 25,
                'registration_token_units' => 25_000,
                'registration_reward_model_aliases' => $allPublicAliases,
                'commission_bps' => 1000,
                'referred_bonus_bps' => 500,
                'minimum_order_minor' => 100,
                'cookie_days' => 30,
                'reward_expiry_days' => 90,
                'commission_all_orders' => true,
                'referred_bonus_first_order_only' => true,
            ],
        );

        $packageSlugs = [];
        foreach ($this->packageDefinitions($routeAliases) as $definition) {
            $requiredAliases = $definition['aliases'];
            unset($definition['aliases']);
            $packageSlugs[] = $definition['slug'];

            $package = Package::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + [
                    'currency' => 'USD',
                    'duration_seconds' => 30 * self::DAY,
                    'limits' => $this->customerLimits(),
                    'auto_creates_api_key' => true,
                    'minimum_margin_bps' => self::PACKAGE_MINIMUM_MARGIN_BPS,
                    'profitability_override_reason' => null,
                    'stock_quantity' => null,
                    'enabled' => true,
                    'customer_visible' => true,
                ],
            );

            $package->modelAliases()->sync(
                collect($requiredAliases)->map(fn (string $alias): int => (int) $aliasIds[$alias])->all()
            );
        }

        Provider::query()->where('id', '!=', $provider->id)->update(['enabled' => false]);
        AiModel::query()->whereNotIn('id', $modelIds)->update(['enabled' => false]);
        ModelAlias::query()->whereNotIn('id', array_values($aliasIds))->update([
            'enabled' => false,
            'customer_visible' => false,
        ]);
        Package::query()->whereNotIn('slug', $packageSlugs)->update([
            'enabled' => false,
            'customer_visible' => false,
        ]);

        if ($this->command) {
            $routeReady = $provider->fresh()->activeConnectionRevision?->isRouteReady() ?? false;
            $this->command->newLine();
            $this->command->info('SP Cambo R44 low-price volume billing seed completed.');
            $this->command->line($bootstrapRevision
                ? 'OmniRoute bootstrap revision exists; probe/activate it in Admin > Providers.'
                : 'Configure OmniRoute in Admin > Providers, then Probe and Activate.');
            $this->command->line('Private combo IDs: Claude, Chatgpt, Gemini, Deepseek.');
            $this->command->line('Public aliases: '.implode(', ', $allPublicAliases).'.');
            $this->command->line('Calculated volume-priced 1-day Token lines + long-life Credit lines seeded for Claude, Codex, Gemini and DeepSeek.');
            $this->command->line('Customer billing: local 1:1 new input/output; locally reused context bills at 0.25x; OmniRoute/provider usage and cost metadata are ignored.');
            $this->command->line('Provider route: '.($routeReady ? 'READY' : 'NOT READY - Probe/activate Admin > Providers > OmniRoute'));
        }
    }

    /** @return array<string,int> */
    private function customerLimits(int $maxOutputTokens = 65_536): array
    {
        return [
            'requests_per_minute' => 60,
            'tokens_per_minute' => 200_000,
            'concurrency' => 4,
            'max_request_bytes' => 1_048_576,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    /** @param array<string,int> $sell @param array<string,int> $reference */
    private function assertLocalReferenceMargin(string $alias, array $sell, array $reference, int $minimumMarginBps): void
    {
        foreach (['input', 'output', 'cache_read', 'cache_write', 'reasoning'] as $class) {
            $price = (int) ($sell[$class] ?? 0);
            $cost = (int) ($reference[$class] ?? 0);
            if ($price <= 0 || $cost < 0 || $cost >= $price) {
                throw new \RuntimeException("R29 local pricing for {$alias} {$class} is invalid.");
            }
            $marginBps = intdiv(($price - $cost) * 10_000, $price);
            if ($marginBps < $minimumMarginBps) {
                throw new \RuntimeException("R29 local reference margin for {$alias} {$class} is below target.");
            }
        }
    }

    private function seedLocalOmniRouteBootstrap(Provider $provider): ?ProviderConnectionRevision
    {
        $path = (string) config('services.spcambo.omniroute_bootstrap_file', storage_path('app/private/omniroute-bootstrap.json'));
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $origin = rtrim(trim((string) ($decoded['origin'] ?? '')), '/');
        $credential = trim((string) ($decoded['credential'] ?? ''));
        if ($origin === '' || $credential === '') {
            return null;
        }

        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->where('route_version', 1)
            ->first();
        if ($revision) {
            return $revision;
        }

        return ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => $origin,
            'connection_type' => 'omniroute',
            'credential' => $credential,
            'credential_suffix' => substr($credential, -8),
            'timeout_ms' => 60_000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
            'last_probe_status' => null,
            'last_probe_at' => null,
        ]);
    }

    /** @param array<string,list<string>> $routeAliases @return list<array<string,mixed>> */
    private function packageDefinitions(array $routeAliases): array
    {
        $packages = [];

        foreach (self::TOKEN_PRICE_PROFILES as $routeKey => $profile) {
            $route = self::ROUTES[$routeKey];
            $prices = $this->calculatedPrices($profile, $route, 1_000_000);
            $this->assertPriceProfile("{$routeKey} Token", $prices);

            foreach (array_keys($profile['multipliers_bps']) as $index => $millions) {
                $label = $profile['label'];
                $packages[] = $this->tokenPackage(
                    "{$routeKey}-token-{$millions}m",
                    $millions === 1000 ? "{$label} 1B Tokens" : "{$label} {$millions}M Tokens",
                    $label,
                    $millions * 1_000_000,
                    $prices[$millions],
                    $profile['sort_order'] + $index,
                    $routeAliases[$routeKey],
                    $millions === 100,
                    self::DAY,
                    $route,
                );
            }
        }

        // Credits are quota-backed platform units, not USD, withdrawable cash,
        // raw provider tokens or an official provider price. One displayed Credit
        // settles as exactly 100,000 SP Cambo billable Tokens.
        foreach (self::CREDIT_PRICE_PROFILES as $routeKey => $profile) {
            $route = self::ROUTES[$routeKey];
            $prices = $this->calculatedPrices($profile, $route, self::SP_CREDIT_UNITS);
            $this->assertPriceProfile("{$routeKey} Credit", $prices);

            foreach (array_keys($profile['multipliers_bps']) as $index => $credits) {
                $label = $profile['label'];
                $packages[] = $this->creditQuotaPackage(
                    "{$routeKey}-credit-{$credits}",
                    "{$label} $".number_format($credits)." ".($credits === 1 ? "Credit" : "Credits"),
                    $label,
                    $credits,
                    $prices[$credits],
                    $profile['sort_order'] + $index,
                    $routeAliases[$routeKey],
                    $credits === 100,
                    $route,
                );
            }
        }

        return $packages;
    }

    /**
     * @param array{anchor_units:int,anchor_price_minor:int,multipliers_bps:array<int,int>} $profile
     * @param array<string,mixed> $route
     */
    private function priceFromProfile(array $profile, int $units, array $route, int $billableTokenUnits): int
    {
        $multiplierBps = (int) ($profile['multipliers_bps'][$units] ?? 0);
        if ($units <= 0 || $billableTokenUnits <= 0 || $profile['anchor_units'] <= 0 || $profile['anchor_price_minor'] <= 0 || $multiplierBps <= 0) {
            throw new \RuntimeException('R44 package price profile contains an invalid value.');
        }

        $numerator = $profile['anchor_price_minor'] * $units * $multiplierBps;
        $denominator = $profile['anchor_units'] * 10_000;
        $rawPriceMinor = intdiv($numerator + $denominator - 1, $denominator);
        $curvePriceMinor = $this->friendlyPriceMinor($rawPriceMinor);
        $referenceCostMinor = intdiv(
            ($billableTokenUnits * $this->referenceCostPerMillionMinor($route)) + 999_999,
            1_000_000,
        );
        $marginDenominator = 10_000 - self::PACKAGE_MINIMUM_MARGIN_BPS;
        $minimumSafePriceMinor = intdiv(
            ($referenceCostMinor * 10_000) + $marginDenominator - 1,
            $marginDenominator,
        );

        return max($curvePriceMinor, $this->friendlyPriceMinor($minimumSafePriceMinor));
    }

    /**
     * @param array{anchor_units:int,anchor_price_minor:int,multipliers_bps:array<int,int>} $profile
     * @param array<string,mixed> $route
     * @return array<int,int>
     */
    private function calculatedPrices(array $profile, array $route, int $billableTokensPerUnit): array
    {
        $prices = [];
        foreach (array_keys($profile['multipliers_bps']) as $units) {
            $prices[$units] = $this->priceFromProfile(
                $profile,
                $units,
                $route,
                $units * $billableTokensPerUnit,
            );
        }

        // Work backwards so friendly x.x9 rounding cannot make a larger tier
        // accidentally cost more per unit than the tier immediately below it.
        $units = array_keys($prices);
        for ($index = count($units) - 2; $index >= 0; $index--) {
            $currentUnits = $units[$index];
            $nextUnits = $units[$index + 1];
            $minimumForCurve = intdiv(
                ($prices[$nextUnits] * $currentUnits) + $nextUnits - 1,
                $nextUnits,
            );
            $prices[$currentUnits] = max($prices[$currentUnits], $this->friendlyPriceMinor($minimumForCurve));
        }

        // V20.7: small retail increase over the already-calculated safe/volume curve.
        foreach ($prices as $unitKey => $priceMinor) {
            $markedUp = intdiv(
                ($priceMinor * (10_000 + self::PACKAGE_PRICE_MARKUP_BPS)) + 9_999,
                10_000,
            );
            $prices[$unitKey] = $this->friendlyPriceMinor($markedUp);
        }

        // Friendly rounding can shift a boundary by a cent; normalize once more
        // so larger packages never become more expensive per unit.
        $units = array_keys($prices);
        for ($index = count($units) - 2; $index >= 0; $index--) {
            $currentUnits = $units[$index];
            $nextUnits = $units[$index + 1];
            $minimumForCurve = intdiv(
                ($prices[$nextUnits] * $currentUnits) + $nextUnits - 1,
                $nextUnits,
            );
            $prices[$currentUnits] = max(
                $prices[$currentUnits],
                $this->friendlyPriceMinor($minimumForCurve),
            );
        }
        return $prices;
    }

    /** @param array<string,mixed> $route */
    private function referenceCostPerMillionMinor(array $route): int
    {
        $worst = 0;
        foreach (['input', 'output', 'cache_read'] as $class) {
            $reference = (int) ($route['reference'][$class] ?? 0);
            $weight = max(1, (int) ($route['weights'][$class] ?? 1_000_000));
            $normalizedExponentThree = intdiv(($reference * 1_000_000) + $weight - 1, $weight);
            $packageMinor = intdiv($normalizedExponentThree + 9, 10);
            $worst = max($worst, $packageMinor);
        }

        if ($worst <= 0) {
            throw new \RuntimeException('R44 package price profile has no positive private reference floor.');
        }

        return $worst;
    }

    private function friendlyPriceMinor(int $priceMinor): int
    {
        // Round up to a customer-friendly amount ending in 9 cents without using floats.
        return $priceMinor < 10
            ? $priceMinor
            : (intdiv($priceMinor + 10, 10) * 10) - 1;
    }

    /** @param array<int,int> $prices */
    private function assertPriceProfile(string $label, array $prices): void
    {
        $previousUnits = 0;
        $previousPriceMinor = 0;

        foreach ($prices as $units => $priceMinor) {
            if ($units <= $previousUnits || $priceMinor <= $previousPriceMinor) {
                throw new \RuntimeException("R44 {$label} tiers must increase in units and total price.");
            }

            if ($previousUnits > 0 && $priceMinor * $previousUnits > $previousPriceMinor * $units) {
                throw new \RuntimeException("R44 {$label} effective unit price must not increase for a larger tier.");
            }

            $previousUnits = $units;
            $previousPriceMinor = $priceMinor;
        }
    }

    /** @param list<string> $aliases @param array<string,mixed> $route @return array<string,mixed> */
    private function tokenPackage(
        string $slug,
        string $name,
        string $familyLabel,
        int $units,
        int $priceMinor,
        int $sortOrder,
        array $aliases,
        bool $featured,
        int $durationSeconds,
        array $route,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'subtitle' => '✨ '.number_format($units).' Tokens · 1 day access',
            'badge' => $featured ? 'Popular' : ($units >= 500_000_000 ? 'Best bulk value' : ($units >= 100_000_000 ? 'Volume value' : 'Starter value')),
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => strtolower(str_replace(' ', '-', $familyLabel)),
            'family_label' => $familyLabel,
            'advertised_units' => $units,
            'unit_label' => 'Tokens',
            'price_minor' => $priceMinor,
            'compare_at_price_minor' => null,
            'currency_exponent' => 2,
            'duration_seconds' => $durationSeconds,
            'featured' => $featured,
            'sort_order' => $sortOrder,
            'limits' => $this->customerLimits((int) $route['max_output_tokens']),
            'billing_rules' => [
                'input_weight_microunits' => $route['weights']['input'],
                'output_weight_microunits' => $route['weights']['output'],
                'cache_read_weight_microunits' => $route['weights']['cache_read'],
                'cache_write_weight_microunits' => $route['weights']['cache_write'],
                'reasoning_weight_microunits' => $route['weights']['reasoning'],
                'billing_multipliers_bps' => $route['billing_multipliers_bps'],
                'minimum_request_units' => (int) $route['minimum_request_units'],
                'local_cache_read_billing_bps' => (int) $route['local_cache_read_billing_bps'],
                'metering_method' => 'LOCAL_CACHE_AWARE_V1',
                'pricing_basis' => 'SP_CAMBO_VOLUME_CURVE_R44',
                'package_kind' => 'SP_TOKENS',
            ],
            'aliases' => $aliases,
        ];
    }

    /** @param list<string> $aliases @param array<string,mixed> $route @return array<string,mixed> */
    private function creditQuotaPackage(
        string $slug,
        string $name,
        string $familyLabel,
        int $credits,
        int $priceMinor,
        int $sortOrder,
        array $aliases,
        bool $featured,
        array $route,
    ): array {
        $units = $credits * self::SP_CREDIT_UNITS;

        return [
            'slug' => $slug,
            'name' => $name,
            'subtitle' => '✨ $'.number_format($credits).' '.($credits === 1 ? 'Credit' : 'Credits').' · prepaid AI access',
            'badge' => $featured ? 'Popular credits' : ($credits >= 1000 ? 'Best bulk value' : 'Long-life value'),
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => strtolower(str_replace(' ', '-', $familyLabel)),
            'family_label' => $familyLabel,
            'advertised_units' => $units,
            'unit_label' => 'Tokens',
            'price_minor' => $priceMinor,
            'compare_at_price_minor' => null,
            'currency_exponent' => 2,
            'duration_seconds' => self::LONG_CREDIT_VALIDITY,
            'featured' => $featured,
            'sort_order' => $sortOrder,
            'limits' => $this->customerLimits((int) $route['max_output_tokens']),
            'billing_rules' => [
                'input_weight_microunits' => $route['weights']['input'],
                'output_weight_microunits' => $route['weights']['output'],
                'cache_read_weight_microunits' => $route['weights']['cache_read'],
                'cache_write_weight_microunits' => $route['weights']['cache_write'],
                'reasoning_weight_microunits' => $route['weights']['reasoning'],
                'billing_multipliers_bps' => $route['billing_multipliers_bps'],
                'minimum_request_units' => (int) $route['minimum_request_units'],
                'local_cache_read_billing_bps' => (int) $route['local_cache_read_billing_bps'],
                'metering_method' => 'LOCAL_CACHE_AWARE_V1',
                'pricing_basis' => 'SP_CAMBO_VOLUME_CURVE_R44',
                'display_units' => $credits,
                'display_unit_label' => 'Credits',
                'sp_credit_billable_units' => self::SP_CREDIT_UNITS,
                'package_kind' => 'SP_CREDITS',
            ],
            'aliases' => $aliases,
        ];
    }
}
