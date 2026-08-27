<?php

namespace Tests\Feature\Feature;

use App\Models\AiModel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SellCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PackageCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seed_does_not_create_a_sell_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('packages', 0);
        $this->assertDatabaseCount('providers', 0);
    }

    public function test_sell_catalog_seed_requires_only_the_private_omniroute_base_url_and_token(): void
    {
        config([
            'services.spcambo.sell_catalog_base_url' => '',
            'services.spcambo.sell_catalog_token' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_BASE_URL');
        $this->expectExceptionMessage('ANTHROPIC_AUTH_TOKEN');

        $this->seed(SellCatalogSeeder::class);
    }

    public function test_sell_catalog_does_not_require_a_global_anthropic_model_and_keeps_exact_database_model_ids(): void
    {
        config([
            'services.spcambo.sell_catalog_base_url' => 'http://127.0.0.1:20128/v1',
            'services.spcambo.sell_catalog_token' => 'test-secret',
            // A legacy/global value must not control SP Cambo routing.
            'services.spcambo.sell_catalog_primary_model' => 'Wrong Model Should Be Ignored',
        ]);

        $this->seed(SellCatalogSeeder::class);

        $this->assertSame(
            ['Gemini Google AI Studio', 'OpenAI Codex'],
            AiModel::query()->where('enabled', true)->orderBy('internal_model_id')->pluck('internal_model_id')->all(),
        );
    }
}
