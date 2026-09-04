<?php

namespace Tests\Feature\Feature;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\PackageProfitabilityService;
use Database\Seeders\SellCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32))]);

        $bootstrap = storage_path('framework/testing/omniroute-bootstrap.json');
        file_put_contents($bootstrap, json_encode([
            'origin' => 'http://127.0.0.1:20128/v1',
            'credential' => 'sk-test-local-bootstrap',
        ], JSON_THROW_ON_ERROR));
        config(['services.spcambo.omniroute_bootstrap_file' => $bootstrap]);
    }

    public function test_r44_seed_builds_four_stable_combos_eleven_public_aliases_and_calculated_volume_packages(): void
    {
        $this->seed(SellCatalogSeeder::class);
        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            ['Chatgpt', 'Claude', 'Deepseek', 'Gemini'],
            AiModel::query()->where('enabled', true)->orderBy('internal_model_id')->pluck('internal_model_id')->all(),
        );

        $expectedAliases = [
            '4.8-sol',
            '5.6-sol',
            'deepseek-v4-flash',
            'deepseek-v4-pro',
            'gemini-3.6-flash',
            'gemini-3.6-pro',
            'gemini-google-ai-studio',
            'haiku-4.5',
            'openai-codex',
            'opus-5',
            'sonnet-5',
        ];

        $this->assertSame(
            $expectedAliases,
            ModelAlias::query()
                ->where('enabled', true)
                ->where('customer_visible', true)
                ->orderBy('public_alias')
                ->pluck('public_alias')
                ->all(),
        );

        $this->assertSame(
            'Claude',
            ModelAlias::query()->where('public_alias', 'opus-5')->firstOrFail()->model()->value('internal_model_id'),
        );
        foreach (['deepseek-v4-flash', 'deepseek-v4-pro'] as $deepseekAlias) {
            $this->assertSame(
                'Deepseek',
                ModelAlias::query()->where('public_alias', $deepseekAlias)->firstOrFail()->model()->value('internal_model_id'),
            );
        }
        $this->assertSame(
            'Chatgpt',
            ModelAlias::query()->where('public_alias', '5.6-sol')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'Chatgpt',
            ModelAlias::query()->where('public_alias', '4.8-sol')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'Gemini',
            ModelAlias::query()->where('public_alias', 'gemini-3.6-flash')->firstOrFail()->model()->value('internal_model_id'),
        );

        foreach (ModelAlias::query()->where('enabled', true)->get() as $alias) {
            $this->assertSame(60, (int) ($alias->limits['requests_per_minute'] ?? 0));
            $this->assertSame(200000, (int) ($alias->limits['tokens_per_minute'] ?? 0));
            $this->assertSame(4, (int) ($alias->limits['concurrency'] ?? 0));
            $this->assertSame(1048576, (int) ($alias->limits['max_request_bytes'] ?? 0));
            $this->assertSame(
                (int) ($alias->capabilities['max_output_tokens'] ?? 0),
                (int) ($alias->limits['max_output_tokens'] ?? 0),
            );
            $this->assertSame(
                (int) ($alias->capabilities['context_tokens'] ?? 0),
                (int) ($alias->limits['context_tokens'] ?? 0),
            );
            $this->assertSame('Tokens', $alias->limits['billing_unit_label'] ?? null);
            $this->assertTrue((bool) ($alias->limits['sp_routing_alias'] ?? false));
            $this->assertNotNull($alias->pricing?->upstream_cost_verified_at);
        }

        $haiku = ModelAlias::query()->where('public_alias', 'haiku-4.5')->firstOrFail();
        $this->assertSame(200_000, (int) ($haiku->capabilities['context_tokens'] ?? 0));
        $this->assertSame(64_000, (int) ($haiku->capabilities['max_output_tokens'] ?? 0));
        $this->assertSame('PROVIDER_PUBLIC_SPEC', $haiku->capabilities['capability_basis'] ?? null);

        $opus = ModelAlias::query()->where('public_alias', 'opus-5')->firstOrFail();
        $this->assertSame(1_000_000, (int) ($opus->capabilities['context_tokens'] ?? 0));
        $this->assertSame(128_000, (int) ($opus->capabilities['max_output_tokens'] ?? 0));

        $sol = ModelAlias::query()->where('public_alias', '5.6-sol')->firstOrFail();
        $this->assertSame(1_050_000, (int) ($sol->capabilities['context_tokens'] ?? 0));
        $this->assertSame(128_000, (int) ($sol->capabilities['max_output_tokens'] ?? 0));
        $this->assertSame('PROVIDER_PUBLIC_SPEC', $sol->capabilities['capability_basis'] ?? null);

        foreach (['4.8-sol', 'openai-codex'] as $localCodexAlias) {
            $codexProfile = ModelAlias::query()->where('public_alias', $localCodexAlias)->firstOrFail();
            $this->assertSame(400_000, (int) ($codexProfile->capabilities['context_tokens'] ?? 0));
            $this->assertSame(128_000, (int) ($codexProfile->capabilities['max_output_tokens'] ?? 0));
            $this->assertSame('SP_CAMBO_ROUTE_PROFILE', $codexProfile->capabilities['capability_basis'] ?? null);
        }

        $geminiFlash = ModelAlias::query()->where('public_alias', 'gemini-3.6-flash')->firstOrFail();
        $this->assertSame(1_048_576, (int) ($geminiFlash->capabilities['context_tokens'] ?? 0));
        $this->assertSame(65_536, (int) ($geminiFlash->capabilities['max_output_tokens'] ?? 0));
        $this->assertSame('PROVIDER_PUBLIC_SPEC', $geminiFlash->capabilities['capability_basis'] ?? null);

        foreach (['gemini-3.6-pro', 'gemini-google-ai-studio'] as $localGeminiAlias) {
            $geminiProfile = ModelAlias::query()->where('public_alias', $localGeminiAlias)->firstOrFail();
            $this->assertSame(1_048_576, (int) ($geminiProfile->capabilities['context_tokens'] ?? 0));
            $this->assertSame(65_536, (int) ($geminiProfile->capabilities['max_output_tokens'] ?? 0));
            $this->assertSame('SP_CAMBO_ROUTE_PROFILE', $geminiProfile->capabilities['capability_basis'] ?? null);
        }

        foreach (['deepseek-v4-flash', 'deepseek-v4-pro'] as $deepseekAlias) {
            $deepseek = ModelAlias::query()->where('public_alias', $deepseekAlias)->firstOrFail();
            $this->assertSame(128_000, (int) ($deepseek->capabilities['context_tokens'] ?? 0));
            $this->assertSame(65_536, (int) ($deepseek->capabilities['max_output_tokens'] ?? 0));
            $this->assertFalse((bool) ($deepseek->capabilities['vision'] ?? true));
            $this->assertTrue((bool) ($deepseek->capabilities['reasoning'] ?? false));
            $this->assertSame('SP_CAMBO_ROUTE_PROFILE', $deepseek->capabilities['capability_basis'] ?? null);
        }

        $this->assertSame(
            57,
            Package::query()->where('enabled', true)->where('customer_visible', true)->count(),
        );
        $this->assertSame(0, Package::query()->published()->count());

        // R44 calculated production token prices.
        $claude100 = Package::query()->where('slug', 'claude-token-100m')->firstOrFail();
        $this->assertSame(269, (int) $claude100->price_minor);
        $this->assertSame(100_000_000, (int) $claude100->advertised_units);
        $this->assertSame(86400, (int) $claude100->duration_seconds);
        $this->assertSame(128_000, (int) ($claude100->limits['max_output_tokens'] ?? 0));
        $this->assertSame(
            ['haiku-4.5', 'opus-5', 'sonnet-5'],
            $claude100->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $codex50 = Package::query()->where('slug', 'codex-token-50m')->firstOrFail();
        $this->assertSame(199, (int) $codex50->price_minor);
        $this->assertSame(128_000, (int) ($codex50->limits['max_output_tokens'] ?? 0));
        $this->assertSame(
            ['4.8-sol', '5.6-sol', 'openai-codex'],
            $codex50->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $gemini100 = Package::query()->where('slug', 'gemini-token-100m')->firstOrFail();
        $this->assertSame(269, (int) $gemini100->price_minor);
        $this->assertSame(65_536, (int) ($gemini100->limits['max_output_tokens'] ?? 0));
        $this->assertSame(
            ['gemini-3.6-flash', 'gemini-3.6-pro', 'gemini-google-ai-studio'],
            $gemini100->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $deepseek100 = Package::query()->where('slug', 'deepseek-token-100m')->firstOrFail();
        $this->assertSame(149, (int) $deepseek100->price_minor);
        $this->assertSame(65_536, (int) ($deepseek100->limits['max_output_tokens'] ?? 0));
        $this->assertSame(
            ['deepseek-v4-flash', 'deepseek-v4-pro'],
            $deepseek100->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $credit = Package::query()->where('slug', 'claude-credit-50')->firstOrFail();
        $this->assertSame('TOKEN_QUOTA', $credit->billing_mode);
        $this->assertSame('Claude $50 Credits', $credit->name);
        $this->assertSame(99, (int) $credit->price_minor);
        $this->assertSame(5_000_000, (int) $credit->advertised_units);
        $this->assertSame(50, (int) ($credit->billing_rules['display_units'] ?? 0));
        $this->assertSame('Credits', $credit->billing_rules['display_unit_label'] ?? null);
        $this->assertSame(100_000, (int) ($credit->billing_rules['sp_credit_billable_units'] ?? 0));
        $this->assertSame('SP_CAMBO_VOLUME_CURVE_R44', $credit->billing_rules['pricing_basis'] ?? null);
        $this->assertSame('SP_CREDITS', $credit->billing_rules['package_kind'] ?? null);

        $token = Package::query()->where('slug', 'claude-token-10m')->firstOrFail();
        $this->assertSame('SP_TOKENS', $token->billing_rules['package_kind'] ?? null);

        foreach (['claude', 'codex', 'gemini', 'deepseek'] as $family) {
            foreach (['SP_TOKENS', 'SP_CREDITS'] as $kind) {
                $line = Package::query()->where('enabled', true)->get()
                    ->filter(fn (Package $package): bool => str_starts_with($package->slug, $family.'-')
                        && ($package->billing_rules['package_kind'] ?? null) === $kind)
                    ->sortBy('advertised_units')
                    ->values();

                for ($index = 1; $index < $line->count(); $index++) {
                    $previous = $line[$index - 1];
                    $current = $line[$index];
                    $this->assertGreaterThan((int) $previous->advertised_units, (int) $current->advertised_units);
                    $this->assertGreaterThan((int) $previous->price_minor, (int) $current->price_minor);
                    $this->assertLessThanOrEqual(
                        (int) $previous->price_minor * (int) $current->advertised_units,
                        (int) $current->price_minor * (int) $previous->advertised_units,
                        "{$family} {$kind} unit price increased at {$current->slug}",
                    );
                }
            }
        }

        $profitability = app(PackageProfitabilityService::class);
        foreach (Package::query()->where('enabled', true)->get() as $package) {
            $analysis = $profitability->analyze($package);
            $this->assertTrue($analysis['reviewable'], "{$package->slug} was not profitability-reviewable");
            $this->assertTrue($analysis['profitable'], "{$package->slug} did not meet its minimum margin");
            $this->assertNull($package->profitability_override_reason);
        }

        $setting = PlaygroundSetting::current();
        $this->assertSame(200_000, (int) $setting->daily_token_quota);
        $this->assertSame(65_536, (int) $setting->max_output_tokens);
        $this->assertSame('gemini-3.6-flash', $setting->default_model_alias);
        $this->assertSame(
            $expectedAliases,
            collect($setting->allowed_model_aliases)->sort()->values()->all(),
        );

        $this->assertDatabaseCount('provider_connection_revisions', 1);
        $revision = ProviderConnectionRevision::query()->sole();
        $this->assertSame(ProviderConnectionRevision::STATUS_PENDING, $revision->lifecycle_status);
        $this->assertNull(
            Provider::query()->where('slug', 'omniroute-primary')->value('active_connection_revision_id'),
        );
    }

    public function test_reseed_preserves_public_to_private_combo_mapping(): void
    {
        $this->seed(SellCatalogSeeder::class);

        ModelAlias::query()->where('public_alias', '5.6-sol')->update([
            'display_name' => 'Temporary label',
        ]);
        ModelAlias::query()->where('public_alias', 'opus-5')->update([
            'display_name' => 'Temporary Claude label',
        ]);

        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            'GPT-5.6 Sol',
            ModelAlias::query()->where('public_alias', '5.6-sol')->value('display_name'),
        );
        $this->assertSame(
            'Claude Opus 5',
            ModelAlias::query()->where('public_alias', 'opus-5')->value('display_name'),
        );
        $this->assertSame(
            'OpenAI Codex',
            ModelAlias::query()->where('public_alias', '5.6-sol')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'Claude',
            ModelAlias::query()->where('public_alias', 'opus-5')->firstOrFail()->model()->value('internal_model_id'),
        );
    }
}
