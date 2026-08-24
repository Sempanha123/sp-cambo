<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_catalog_is_a_successful_empty_array(): void
    {
        $this->getJson('/api/v1/catalog/models')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_only_commercially_verified_published_aliases_are_returned_without_internal_ids(): void
    {
        $provider = Provider::query()->create(['name' => 'Private router', 'slug' => 'private-router', 'enabled' => true]);
        $verified = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'internal-secret-route-name',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $verified->id,
            'public_alias' => 'claude-coding',
            'display_name' => 'Claude Coding',
            'description' => null,
            'capabilities' => [
                'streaming' => true, 'tools' => true, 'vision' => false, 'reasoning' => true,
                'messages_api' => true, 'responses_api' => false,
                'context_tokens' => 200000, 'max_output_tokens' => 64000,
            ],
            'limits' => ['requests_per_minute' => 60, 'tokens_per_minute' => 200000, 'concurrency' => 4],
            'status' => 'available',
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $alias->pricing()->create([
            'currency' => 'USD', 'exponent' => 2,
            'input_per_million_minor' => 300, 'output_per_million_minor' => 1500,
        ]);

        $unverified = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'unverified-route',
            'family' => 'other', 'family_label' => 'Other', 'enabled' => true,
        ]);
        ModelAlias::query()->create([
            'ai_model_id' => $unverified->id, 'public_alias' => 'must-not-publish',
            'display_name' => 'Hidden', 'capabilities' => [], 'limits' => [],
            'status' => 'available', 'enabled' => true, 'customer_visible' => true,
        ]);

        $response = $this->getJson('/api/v1/catalog/models')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.public_alias', 'claude-coding')
            ->assertJsonPath('data.0.capabilities.messages_api', true)
            ->assertJsonPath('data.0.capabilities.responses_api', false)
            ->assertJsonPath('data.0.credit_pricing.input_per_million.minor', '300')
            ->assertJsonMissingPath('data.0.internal_model_id')
            ->assertJsonMissingPath('data.0.provider')
            ->assertJsonMissingPath('data.1');

        $this->assertStringNotContainsString('internal-secret-route-name', $response->getContent());
    }
}
