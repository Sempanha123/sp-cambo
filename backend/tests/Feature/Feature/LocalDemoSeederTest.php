<?php

namespace Tests\Feature\Feature;

use App\Models\ModelAlias;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'services.spcambo.demo_upstream_token' => 'test-demo-token',
            'services.spcambo.demo_upstream_base_url' => 'http://127.0.0.1:20128/v1',
            'services.spcambo.demo_upstream_model' => 'OpenAI Codex',
            'services.spcambo.demo_public_alias' => 'openai-codex',
            'services.spcambo.demo_protocols' => 'messages',
        ]);
    }

    public function test_database_seed_builds_one_runnable_demo_model_four_sell_products_and_20k_daily_playground_policy(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $provider = Provider::query()->where('slug', 'local-demo-upstream')->sole();
        $revision = $provider->activeConnectionRevision()->firstOrFail();
        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->lifecycle_status);

        $alias = ModelAlias::query()->where('public_alias', 'openai-codex')->sole();
        $this->assertTrue((bool) ($alias->capabilities['messages_api'] ?? false));
        $this->assertTrue(ModelAlias::query()->published()->whereKey($alias->id)->exists());

        $this->assertDatabaseCount('packages', 4);
        $this->assertSame(4, \App\Models\Package::query()->where('enabled', true)->where('customer_visible', true)->count());
        $this->assertSame(4, \Illuminate\Support\Facades\DB::table('model_alias_package')->where('model_alias_id', $alias->id)->count());

        $setting = PlaygroundSetting::current();
        $this->assertSame(20_000, (int) $setting->daily_token_quota);
        $this->assertSame(['openai-codex'], $setting->allowed_model_aliases);
        $this->assertSame('openai-codex', $setting->default_model_alias);
    }
}
