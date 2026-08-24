<?php

namespace Tests\Feature\Feature;

use Database\Seeders\PackageCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_catalog_is_idempotent_editable_and_never_published_by_default(): void
    {
        $this->seed(PackageCatalogSeeder::class);
        $this->seed(PackageCatalogSeeder::class);

        $this->assertDatabaseCount('packages', 6);
        $this->assertDatabaseHas('packages', ['slug' => 'token-quota-5m', 'advertised_units' => 5_000_000, 'price_minor' => 50, 'enabled' => false, 'customer_visible' => false]);
        $this->assertDatabaseHas('packages', ['slug' => 'token-quota-200m', 'advertised_units' => 200_000_000, 'price_minor' => 1000]);
        $this->getJson('/api/v1/catalog/packages')->assertOk()->assertExactJson(['data' => []]);
    }
}
