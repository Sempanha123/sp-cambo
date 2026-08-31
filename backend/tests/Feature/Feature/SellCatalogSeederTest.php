<?php

namespace Tests\Feature\Feature;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
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

    public function test_r27_seed_builds_three_stable_combos_nine_public_aliases_and_competitive_packages(): void
    {
        $this->seed(SellCatalogSeeder::class);
        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            ['AgentRouter-claude-opus-5', 'Gemini Google AI Studio', 'OpenAI Codex'],
            AiModel::query()->where('enabled', true)->orderBy('internal_model_id')->pluck('internal_model_id')->all(),
        );

        $expectedAliases = [
            '4.8-sol',
            '5.6-sol',
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
            'AgentRouter-claude-opus-5',
            ModelAlias::query()->where('public_alias', 'opus-5')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'OpenAI Codex',
            ModelAlias::query()->where('public_alias', '5.6-sol')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'OpenAI Codex',
            ModelAlias::query()->where('public_alias', '4.8-sol')->firstOrFail()->model()->value('internal_model_id'),
        );
        $this->assertSame(
            'Gemini Google AI Studio',
            ModelAlias::query()->where('public_alias', 'gemini-3.6-flash')->firstOrFail()->model()->value('internal_model_id'),
        );

        foreach (ModelAlias::query()->where('enabled', true)->get() as $alias) {
            $this->assertSame(60, (int) ($alias->limits['requests_per_minute'] ?? 0));
            $this->assertSame(200000, (int) ($alias->limits['tokens_per_minute'] ?? 0));
            $this->assertSame(4, (int) ($alias->limits['concurrency'] ?? 0));
            $this->assertSame(1048576, (int) ($alias->limits['max_request_bytes'] ?? 0));
            $this->assertSame(65536, (int) ($alias->limits['max_output_tokens'] ?? 0));
            $this->assertSame('Tokens', $alias->limits['billing_unit_label'] ?? null);
            $this->assertTrue((bool) ($alias->limits['sp_routing_alias'] ?? false));
            $this->assertNotNull($alias->pricing?->upstream_cost_verified_at);
        }

        $this->assertSame(
            43,
            Package::query()->where('enabled', true)->where('customer_visible', true)->count(),
        );
        $this->assertSame(0, Package::query()->published()->count());

        // R43 current production token prices.
        $claude100 = Package::query()->where('slug', 'claude-token-100m')->firstOrFail();
        $this->assertSame(259, (int) $claude100->price_minor);
        $this->assertSame(100_000_000, (int) $claude100->advertised_units);
        $this->assertSame(86400, (int) $claude100->duration_seconds);
        $this->assertSame(
            ['haiku-4.5', 'opus-5', 'sonnet-5'],
            $claude100->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $codex50 = Package::query()->where('slug', 'codex-token-50m')->firstOrFail();
        $this->assertSame(379, (int) $codex50->price_minor);
        $this->assertSame(
            ['4.8-sol', '5.6-sol', 'openai-codex'],
            $codex50->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $gemini100 = Package::query()->where('slug', 'gemini-token-100m')->firstOrFail();
        $this->assertSame(209, (int) $gemini100->price_minor);
        $this->assertSame(
            ['gemini-3.6-flash', 'gemini-3.6-pro', 'gemini-google-ai-studio'],
            $gemini100->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );

        $credit = Package::query()->where('slug', 'claude-credit-50')->firstOrFail();
        $this->assertSame('TOKEN_QUOTA', $credit->billing_mode);
        $this->assertSame('Claude $50 Credits', $credit->name);
        $this->assertSame(5_000_000, (int) $credit->advertised_units);
        $this->assertSame(50, (int) ($credit->billing_rules['display_units'] ?? 0));
        $this->assertSame('Credits', $credit->billing_rules['display_unit_label'] ?? null);
        $this->assertSame(100_000, (int) ($credit->billing_rules['sp_credit_billable_units'] ?? 0));
        $this->assertSame('SP_CREDITS', $credit->billing_rules['package_kind'] ?? null);

        $token = Package::query()->where('slug', 'claude-token-10m')->firstOrFail();
        $this->assertSame('SP_TOKENS', $token->billing_rules['package_kind'] ?? null);

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
            'AgentRouter-claude-opus-5',
            ModelAlias::query()->where('public_alias', 'opus-5')->firstOrFail()->model()->value('internal_model_id'),
        );
    }
}
