<?php

namespace Tests\Feature\Feature;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SellCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'services.spcambo.playground_daily_token_quota' => 20_000,
            // This test covers the Admin-UI-only path, so no bootstrap revision.
            'services.spcambo.omniroute_bootstrap_file' => storage_path('framework/testing/does-not-exist.json'),
        ]);
    }

    public function test_migrate_fresh_seed_bootstraps_four_combo_catalog_without_provider_env_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $provider = Provider::query()->where('slug', 'omniroute-primary')->sole();
        $this->assertTrue($provider->enabled);
        $this->assertNull($provider->active_connection_revision_id);
        $this->assertDatabaseCount('provider_connection_revisions', 0);

        $this->assertSame(
            ['Chatgpt', 'Claude', 'Deepseek', 'Gemini'],
            AiModel::query()->where('enabled', true)->orderBy('internal_model_id')->pluck('internal_model_id')->all(),
        );
        $this->assertSame(10, ModelAlias::query()->where('enabled', true)->where('customer_visible', true)->count());
        $this->assertSame(57, Package::query()->where('enabled', true)->where('customer_visible', true)->count());

        // Nothing is publicly sellable until the Admin-managed route is READY.
        $this->assertSame(0, ModelAlias::query()->published()->count());
        $this->assertSame(0, Package::query()->published()->count());
    }

    public function test_catalog_becomes_sellable_after_ui_managed_ready_revision_is_activated(): void
    {
        $this->seed(DatabaseSeeder::class);
        $provider = Provider::query()->where('slug', 'omniroute-primary')->sole();

        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://127.0.0.1:20128',
            'connection_type' => 'omniroute',
            'credential' => 'ui-managed-test-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 60000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->activateConnectionRevision($revision);

        $this->assertSame(10, ModelAlias::query()->published()->count());
        $this->assertSame(57, Package::query()->published()->count());
        $this->assertSame(57, Package::query()->published()->where('billing_mode', 'TOKEN_QUOTA')->count());
    }

    public function test_sell_catalog_keeps_exact_private_combo_ids(): void
    {
        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            ['Chatgpt', 'Claude', 'Deepseek', 'Gemini'],
            AiModel::query()->where('enabled', true)->orderBy('internal_model_id')->pluck('internal_model_id')->all(),
        );
    }
}
