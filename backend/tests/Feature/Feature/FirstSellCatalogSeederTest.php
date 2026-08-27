<?php

namespace Tests\Feature\Feature;

use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use Database\Seeders\FirstSellSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstSellCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'services.spcambo.first_sell_token' => 'test-first-sell-token',
            'services.spcambo.first_sell_base_url' => 'http://127.0.0.1:20128/v1',
            'services.spcambo.first_sell_model' => 'OpenAI Codex',
            'services.spcambo.playground_daily_token_quota' => 20_000,
        ]);
    }

    public function test_first_sell_seed_builds_one_model_four_products_and_20k_playground_policy_without_demo_users(): void
    {
        $this->seed(FirstSellSeeder::class);
        $this->seed(FirstSellSeeder::class);

        $alias = ModelAlias::query()->where('public_alias', 'openai-codex')->sole();
        $this->assertTrue(ModelAlias::query()->published()->whereKey($alias->id)->exists());

        $this->assertSame(4, Package::query()->whereIn('slug', [
            'openai-codex-10m',
            'openai-codex-50m',
            'openai-codex-credit-10usd',
            'openai-codex-credit-100usd',
        ])->count());
        $this->assertSame(4, Package::query()->published()->count());
        $this->assertSame(4, \Illuminate\Support\Facades\DB::table('model_alias_package')->where('model_alias_id', $alias->id)->count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@spcambo.local']);
        $this->assertDatabaseMissing('users', ['email' => 'customer@spcambo.local']);

        $setting = PlaygroundSetting::current();
        $this->assertSame(20_000, (int) $setting->daily_token_quota);
        $this->assertSame(['openai-codex'], $setting->allowed_model_aliases);
        $this->assertSame('openai-codex', $setting->default_model_alias);
    }
}
