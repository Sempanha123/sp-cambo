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
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'services.spcambo.sell_catalog_token' => 'test-secret',
            'services.spcambo.sell_catalog_base_url' => 'http://127.0.0.1:20128/v1',
        ]);
    }

    public function test_sell_seed_is_idempotent_and_activates_one_omniroute_revision_for_both_models(): void
    {
        $this->seed(SellCatalogSeeder::class);
        $firstCiphertext = ProviderConnectionRevision::query()->firstOrFail()->getRawOriginal('credential');

        $this->seed(SellCatalogSeeder::class);

        $provider = Provider::query()->where('slug', 'omniroute-primary')->sole();
        $this->assertSame('OmniRoute', $provider->name);
        $revision = ProviderConnectionRevision::query()->where('provider_id', $provider->id)->where('route_version', 1)->sole();

        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->lifecycle_status);
        $this->assertSame($revision->id, $provider->active_connection_revision_id);
        $this->assertSame('omniroute', $revision->connection_type);
        $this->assertSame('SUCCESS', $revision->last_probe_status);
        $this->assertSame($firstCiphertext, $revision->getRawOriginal('credential'));
        $this->assertDatabaseCount('provider_connection_revisions', 1);

        $this->assertSame(
            ['Gemini Google AI Studio', 'OpenAI Codex'],
            ModelAlias::query()->published()->orderBy('display_name')->pluck('display_name')->all(),
        );
    }
}
