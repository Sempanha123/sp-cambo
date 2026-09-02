<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_package_catalog_is_a_successful_empty_array(): void
    {
        $this->getJson('/api/v1/catalog/packages')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_catalog_returns_only_currently_published_packages_with_exact_values(): void
    {
        $alias = $this->publishedAlias();
        $package = Package::query()->create([
            'slug' => 'claude-20m-24h', 'name' => 'Claude Coding 20M',
            'billing_mode' => 'TOKEN_QUOTA', 'family' => 'claude', 'family_label' => 'Claude',
            'advertised_units' => 20000000, 'unit_label' => 'tokens', 'price_minor' => 150,
            'currency' => 'USD', 'currency_exponent' => 2, 'duration_seconds' => 86400,
            'limits' => [
                'requests_per_minute' => 60, 'tokens_per_minute' => 200000,
                'concurrency' => 4, 'max_request_bytes' => 1048576, 'max_output_tokens' => 64000,
            ],
            'auto_creates_api_key' => true, 'featured' => true, 'sort_order' => 10,
            'enabled' => true, 'customer_visible' => true,
        ]);
        $package->modelAliases()->attach($alias);

        Package::query()->create([
            'slug' => 'future', 'name' => 'Future', 'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'claude', 'family_label' => 'Claude', 'advertised_units' => 1,
            'unit_label' => 'tokens', 'price_minor' => 1, 'duration_seconds' => 86400,
            'limits' => [], 'starts_at' => now()->addDay(), 'enabled' => true, 'customer_visible' => true,
        ]);

        $this->getJson('/api/v1/catalog/packages')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $package->id)
            ->assertJsonPath('data.0.advertised_units', '20000000')
            ->assertJsonPath('data.0.price.minor', '150')
            ->assertJsonPath('data.0.duration_seconds', 86400)
            ->assertJsonPath('data.0.package_kind', 'SP_TOKENS')
            ->assertJsonPath('data.0.allowed_model_aliases.0', 'claude-coding')
            ->assertJsonMissingPath('data.0.minimum_margin_bps')
            ->assertJsonMissingPath('data.0.profitability')
            ->assertJsonMissingPath('data.0.profitability_override_reason')
            ->assertJsonMissingPath('data.0.upstream_cost');
    }

    private function publishedAlias(): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider', 'enabled' => true]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://127.0.0.1:3010',
            'connection_type' => 'omniroute',
            'credential' => 'test-provider-credential',
            'credential_suffix' => 'test',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create([
            'provider_id' => $provider->id, 'internal_model_id' => 'private-id',
            'family' => 'claude', 'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(), 'enabled' => true,
        ]);

        return ModelAlias::query()->create([
            'ai_model_id' => $model->id, 'public_alias' => 'claude-coding',
            'display_name' => 'Claude Coding', 'capabilities' => ['messages_api' => true, 'responses_api' => false],
            'limits' => [], 'status' => 'active', 'enabled' => true, 'customer_visible' => true,
        ]);
    }
}
