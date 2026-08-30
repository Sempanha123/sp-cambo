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

    /**
     * R29 keeps three stable operator-owned OmniRoute combo IDs. Public aliases are
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
            'internal_model_id' => 'AgentRouter-claude-opus-5',
            'internal_display_name' => 'AgentRouter Claude Combo',
            'family' => 'claude',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_000_000,
            'max_output_tokens' => 128_000,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                'opus-5' => 'Claude Opus 5',
                'sonnet-5' => 'Claude Sonnet 5',
                'haiku-4.5' => 'Claude Haiku 4.5',
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
                'input' => 8,
                'output' => 40,
                'cache_read' => 1,
                'cache_write' => 10,
                'reasoning' => 40,
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
            'internal_model_id' => 'OpenAI Codex',
            'internal_display_name' => 'OpenAI Codex Combo',
            'family' => 'codex',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_050_000,
            'max_output_tokens' => 128_000,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                '5.6-sol' => 'GPT-5.6 Sol',
                '4.8-sol' => 'GPT-4.8 Sol',
                'openai-codex' => 'OpenAI Codex',
            ],
            'sell' => [
                'input' => 75,        // $0.075
                'output' => 375,      // $0.375
                'cache_read' => 19,   // $0.019 local cached input
                'cache_write' => 90,  // $0.090
                'reasoning' => 375,   // $0.375
            ],
            'reference' => [
                'input' => 42,
                'output' => 210,
                'cache_read' => 4,
                'cache_write' => 50,
                'reasoning' => 210,
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
            'internal_model_id' => 'Gemini Google AI Studio',
            'internal_display_name' => 'Gemini Google AI Studio Combo',
            'family' => 'gemini',
            'vision' => true,
            'reasoning' => true,
            'context_tokens' => 1_048_576,
            'max_output_tokens' => 65_536,
            'minimum_request_units' => 0,
            'local_cache_read_billing_bps' => 2_500,
            'aliases' => [
                'gemini-3.6-flash' => 'Gemini 3.6 Flash',
                'gemini-3.6-pro' => 'Gemini 3.6 Pro',
                'gemini-google-ai-studio' => 'Gemini Google AI Studio',
            ],
            'sell' => [
                'input' => 18,        // $0.018
                'output' => 90,       // $0.090
                'cache_read' => 5,    // $0.005 local cached input
                'cache_write' => 18,  // $0.018
                'reasoning' => 90,    // $0.090
            ],
            'reference' => [
                'input' => 8,
                'output' => 40,
                'cache_read' => 1,
                'cache_write' => 8,
                'reasoning' => 40,
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
            foreach ($route['aliases'] as $publicAlias => $displayName) {
                $alias = ModelAlias::query()->firstOrNew(['public_alias' => $publicAlias]);
                $alias->forceFill([
                    'ai_model_id' => $model->id,
                    'display_name' => $displayName,
                    'description' => 'SP Cambo public routing alias. It is served by the operator-configured OmniRoute combo and may use backend failover; the public label does not change the private combo ID.',
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
                        'context_tokens' => (int) $route['context_tokens'],
                        'max_output_tokens' => (int) $route['max_output_tokens'],
                    ],
                    'limits' => $this->customerLimits() + [
                        'context_tokens' => (int) $route['context_tokens'],
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

                $this->assertLocalReferenceMargin($publicAlias, $sell, $reference, 2500);
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
                    'minimum_margin_bps' => 2500,
                    'profitability_override_reason' => 'R43 final margin-balanced policy: SP Cambo meters new input/output 1:1 and locally matched repeated context at 0.25x. OmniRoute/provider usage, cache and cost metadata never control customer billing. Package prices use volume tiers with lower effective unit cost on larger bundles.',
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
            $this->command->info('SP Cambo R43 final margin-balanced billing seed completed.');
            $this->command->line($bootstrapRevision
                ? 'OmniRoute bootstrap revision exists; probe/activate it in Admin > Providers.'
                : 'Configure OmniRoute in Admin > Providers, then Probe and Activate.');
            $this->command->line('Private combo IDs: AgentRouter-claude-opus-5, OpenAI Codex, Gemini Google AI Studio.');
            $this->command->line('Public aliases: '.implode(', ', $allPublicAliases).'.');
            $this->command->line('Final volume-priced 1-day Token lines + long-life dollar Credit lines seeded for Claude, Codex and Gemini.');
            $this->command->line('Customer billing: local 1:1 new input/output; locally reused context bills at 0.25x; OmniRoute/provider usage and cost metadata are ignored.');
            $this->command->line('Provider route: '.($routeReady ? 'READY' : 'NOT READY - Probe/activate Admin > Providers > OmniRoute'));
        }
    }

    /** @return array<string,int> */
    private function customerLimits(): array
    {
        return [
            'requests_per_minute' => 60,
            'tokens_per_minute' => 200_000,
            'concurrency' => 4,
            'max_request_bytes' => 1_048_576,
            'max_output_tokens' => 65_536,
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

        // Competitor screenshots: active Claude token line was roughly $0.75/10M,
        // $1.75/50M, $2.50/100M, $5/200M, $7.50/300M, $10/400M,
        // $12/500M and $17.80/1B. R43 keeps a competitive range while adding a little more margin and preserving lower effective unit prices on larger bundles.
        foreach ([
            [10, 79], [50, 179], [100, 259], [200, 499],
            [300, 739], [400, 969], [500, 1199], [1000, 1799],
        ] as $index => [$millions, $priceMinor]) {
            $packages[] = $this->tokenPackage(
                "claude-token-{$millions}m",
                $millions === 1000 ? 'Claude 1B Tokens' : "Claude {$millions}M Tokens",
                'Claude Token',
                $millions * 1_000_000,
                $priceMinor,
                10 + $index,
                $routeAliases['claude'],
                $millions === 100,
                self::DAY,
                self::ROUTES['claude'],
            );
        }

        // Competitor Codex showed $7.50/100M and $15/200M, while 10M/50M were
        // sold out. R43 keeps those entry tiers available and applies progressive volume pricing.
        foreach ([
            [10, 79], [50, 379], [100, 749], [200, 1449],
            [300, 2099], [500, 3449], [1000, 6499],
        ] as $index => [$millions, $priceMinor]) {
            $packages[] = $this->tokenPackage(
                "codex-token-{$millions}m",
                $millions === 1000 ? 'Codex 1B Tokens' : "Codex {$millions}M Tokens",
                'Codex Token',
                $millions * 1_000_000,
                $priceMinor,
                30 + $index,
                $routeAliases['codex'],
                $millions === 100,
                self::DAY,
                self::ROUTES['codex'],
            );
        }

        // Gemini is the low-price acquisition line.
        foreach ([
            [10, 59], [50, 129], [100, 209], [200, 379],
            [300, 549], [500, 869], [1000, 1499],
        ] as $index => [$millions, $priceMinor]) {
            $packages[] = $this->tokenPackage(
                "gemini-token-{$millions}m",
                $millions === 1000 ? 'Gemini 1B Tokens' : "Gemini {$millions}M Tokens",
                'Gemini Token',
                $millions * 1_000_000,
                $priceMinor,
                50 + $index,
                $routeAliases['gemini'],
                $millions === 100,
                self::DAY,
                self::ROUTES['gemini'],
            );
        }

        // Credits are dollar-denominated platform usage credits backed by quota,
        // not withdrawable cash and not raw provider tokens. $1 Credit =
        // 100,000 platform Tokens for settlement.
        foreach ([
            [50, 159], [100, 269], [200, 489], [500, 1079],
            [1000, 1899], [2000, 2699], [3000, 3299],
        ] as $index => [$credits, $priceMinor]) {
            $packages[] = $this->creditQuotaPackage(
                "claude-credit-{$credits}",
                "Claude $".number_format($credits)." Credits",
                'Claude Credits',
                $credits,
                $priceMinor,
                70 + $index,
                $routeAliases['claude'],
                $credits === 100,
                self::ROUTES['claude'],
            );
        }

        foreach ([
            [50, 299], [100, 519], [200, 919], [500, 2049],
            [1000, 3599], [2000, 6199], [3000, 8199],
        ] as $index => [$credits, $priceMinor]) {
            $packages[] = $this->creditQuotaPackage(
                "codex-credit-{$credits}",
                "Codex $".number_format($credits)." Credits",
                'Codex Credits',
                $credits,
                $priceMinor,
                90 + $index,
                $routeAliases['codex'],
                $credits === 100,
                self::ROUTES['codex'],
            );
        }

        foreach ([
            [50, 99], [100, 169], [200, 319], [500, 699],
            [1000, 1199], [2000, 2099], [3000, 2899],
        ] as $index => [$credits, $priceMinor]) {
            $packages[] = $this->creditQuotaPackage(
                "gemini-credit-{$credits}",
                "Gemini $".number_format($credits)." Credits",
                'Gemini Credits',
                $credits,
                $priceMinor,
                110 + $index,
                $routeAliases['gemini'],
                $credits === 100,
                self::ROUTES['gemini'],
            );
        }

        return $packages;
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
            'subtitle' => number_format($units).' Tokens. New input/output uses 1:1; locally reused context uses 0.25x. Larger bundles have a lower effective unit price. Valid for 1 day.',
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
            'subtitle' => '$'.number_format($credits).' Credits. $1 Credit = '.number_format(self::SP_CREDIT_UNITS).' billable Tokens. Locally reused context uses 0.25x. Larger bundles lower the effective purchase price. Platform usage credit only; not withdrawable cash.',
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
                'display_units' => $credits,
                'display_unit_label' => 'Credits',
                'sp_credit_billable_units' => self::SP_CREDIT_UNITS,
                'package_kind' => 'SP_CREDITS',
            ],
            'aliases' => $aliases,
        ];
    }
}
