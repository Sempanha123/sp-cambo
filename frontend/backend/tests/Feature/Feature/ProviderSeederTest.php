<?php

namespace Tests\Feature\Feature;

use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Database\Seeders\SellCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        $bootstrap = storage_path('framework/testing/omniroute-bootstrap.json');
        file_put_contents($bootstrap, json_encode([
            'origin' => 'http://127.0.0.1:20128/v1',
            'credential' => 'sk-test-local-bootstrap',
        ], JSON_THROW_ON_ERROR));
        config(['services.spcambo.omniroute_bootstrap_file' => $bootstrap]);
    }

    public function test_sell_seed_is_idempotent_and_creates_one_pending_local_bootstrap_revision(): void
    {
        $this->seed(SellCatalogSeeder::class);
        $this->seed(SellCatalogSeeder::class);

        $provider = Provider::query()->where('slug', 'omniroute-primary')->sole();
        $this->assertSame('OmniRoute', $provider->name);
        $this->assertNull($provider->active_connection_revision_id);
        $this->assertDatabaseCount('provider_connection_revisions', 1);
        $revision = ProviderConnectionRevision::query()->sole();
        $this->assertSame(ProviderConnectionRevision::STATUS_PENDING, $revision->lifecycle_status);
        $this->assertSame('http://127.0.0.1:20128/v1', $revision->origin);
        $this->assertStringStartsWith('sk-', (string) $revision->credential);

        $this->assertSame(
            ['Claude Haiku 4.5', 'Claude Opus 5', 'Claude Sonnet 5', 'GPT-4.8 Sol', 'GPT-5.6 Sol', 'Gemini 3.6 Flash', 'Gemini 3.6 Pro', 'Gemini Google AI Studio', 'OpenAI Codex'],
            ModelAlias::query()->where('enabled', true)->orderBy('display_name')->pluck('display_name')->all(),
        );
        $this->assertSame(0, ModelAlias::query()->published()->count());
    }
    public function test_reseed_never_overwrites_an_operator_edited_pending_revision(): void
    {
        $this->seed(SellCatalogSeeder::class);

        $revision = ProviderConnectionRevision::query()->sole();
        $revision->forceFill([
            'origin' => 'http://127.0.0.1:29999/v1',
            'credential' => 'sk-operator-edited-secret',
            'credential_suffix' => 'd-secret',
        ])->save();

        $this->seed(SellCatalogSeeder::class);

        $revision->refresh();
        $this->assertSame('http://127.0.0.1:29999/v1', $revision->origin);
        $this->assertSame('sk-operator-edited-secret', $revision->credential);
        $this->assertDatabaseCount('provider_connection_revisions', 1);
    }

}
