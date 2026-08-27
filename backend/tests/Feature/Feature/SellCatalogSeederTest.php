<?php

namespace Tests\Feature\Feature;

use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use Database\Seeders\SellCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'services.spcambo.sell_catalog_token' => 'test-sell-token',
            'services.spcambo.sell_catalog_base_url' => 'http://127.0.0.1:20128/v1',
            'services.spcambo.playground_daily_token_quota' => 20_000,
        ]);
    }

    public function test_sell_seed_builds_only_two_public_models_six_products_and_20k_playground_policy(): void
    {
        Provider::query()->create(['slug' => 'old-demo-provider', 'name' => 'Old Demo', 'enabled' => true]);
        Package::query()->create([
            'slug' => 'old-demo-package',
            'name' => 'Old Demo Package',
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'demo',
            'family_label' => 'Demo',
            'advertised_units' => 100,
            'unit_label' => 'tokens',
            'price_minor' => 1,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'duration_seconds' => 3600,
            'limits' => [],
            'billing_rules' => [],
            'auto_creates_api_key' => true,
            'featured' => false,
            'sort_order' => 999,
            'enabled' => true,
            'customer_visible' => true,
            'minimum_margin_bps' => 0,
        ]);

        $this->seed(SellCatalogSeeder::class);
        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            ['gemini-google-ai-studio', 'openai-codex'],
            ModelAlias::query()->where('enabled', true)->orderBy('public_alias')->pluck('public_alias')->all(),
        );
        $this->assertSame(2, ModelAlias::query()->published()->count());
        foreach (ModelAlias::query()->published()->get() as $alias) {
            $this->assertTrue((bool) ($alias->capabilities['messages_api'] ?? false));
            $this->assertFalse((bool) ($alias->capabilities['responses_api'] ?? false));
            $this->assertFalse((bool) ($alias->capabilities['chat_completions_api'] ?? false));
        }

        $expectedPackages = [
            'gemini-google-ai-studio-10m',
            'gemini-google-ai-studio-50m',
            'multi-model-credit-100usd',
            'multi-model-credit-10usd',
            'openai-codex-10m',
            'openai-codex-50m',
        ];
        $this->assertSame(
            $expectedPackages,
            Package::query()->where('enabled', true)->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(6, Package::query()->published()->count());

        $this->assertFalse((bool) Provider::query()->where('slug', 'old-demo-provider')->value('enabled'));
        $this->assertFalse((bool) Package::query()->where('slug', 'old-demo-package')->value('enabled'));
        $this->assertDatabaseMissing('users', ['email' => 'admin@spcambo.local']);
        $this->assertDatabaseMissing('users', ['email' => 'customer@spcambo.local']);

        $setting = PlaygroundSetting::current();
        $this->assertSame(20_000, (int) $setting->daily_token_quota);
        $this->assertSame(['openai-codex', 'gemini-google-ai-studio'], $setting->allowed_model_aliases);
        $this->assertSame('openai-codex', $setting->default_model_alias);

        $credit = Package::query()->where('slug', 'multi-model-credit-10usd')->firstOrFail();
        $this->assertSame(
            ['gemini-google-ai-studio', 'openai-codex'],
            $credit->modelAliases()->orderBy('public_alias')->pluck('public_alias')->all(),
        );
    }
}
