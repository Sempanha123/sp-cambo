<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelPricing;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        // Create OmniRoute provider
        $provider = Provider::query()->updateOrCreate(
            ['slug' => 'omniroute'],
            ['name' => 'OmniRoute', 'enabled' => true]
        );

        // Create AI models for the provider
        $geminiModel = AiModel::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'internal_model_id' => 'all-gemini-3.6-flash'],
            [
                'family' => 'gemini',
                'family_label' => 'Gemini',
                'commercial_resale_verified_at' => now(),
                'enabled' => true,
            ]
        );

        $claudeModel = AiModel::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'internal_model_id' => 'all-claude-3.5-sonnet'],
            [
                'family' => 'claude',
                'family_label' => 'Claude',
                'commercial_resale_verified_at' => now(),
                'enabled' => true,
            ]
        );

        $gptModel = AiModel::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'internal_model_id' => 'all-gpt-4o'],
            [
                'family' => 'gpt',
                'family_label' => 'GPT',
                'commercial_resale_verified_at' => now(),
                'enabled' => true,
            ]
        );

        // Create public model aliases
        $geminiAlias = ModelAlias::query()->updateOrCreate(
            ['public_alias' => 'gemini-3.6-flash'],
            [
                'ai_model_id' => $geminiModel->id,
                'display_name' => 'Gemini 3.6 Flash',
                'description' => 'Fast and efficient multimodal model',
                'capabilities' => ['chat', 'streaming', 'vision'],
                'limits' => ['context_window' => 1000000, 'max_output_tokens' => 8192],
                'status' => 'active',
                'enabled' => true,
                'customer_visible' => true,
            ]
        );

        $claudeAlias = ModelAlias::query()->updateOrCreate(
            ['public_alias' => 'claude-3.5-sonnet'],
            [
                'ai_model_id' => $claudeModel->id,
                'display_name' => 'Claude 3.5 Sonnet',
                'description' => 'Anthropic\'s most intelligent model',
                'capabilities' => ['chat', 'streaming', 'vision', 'tools'],
                'limits' => ['context_window' => 200000, 'max_output_tokens' => 8192],
                'status' => 'active',
                'enabled' => true,
                'customer_visible' => true,
            ]
        );

        $gptAlias = ModelAlias::query()->updateOrCreate(
            ['public_alias' => 'gpt-4o'],
            [
                'ai_model_id' => $gptModel->id,
                'display_name' => 'GPT-4o',
                'description' => 'OpenAI\'s flagship multimodal model',
                'capabilities' => ['chat', 'streaming', 'vision', 'tools'],
                'limits' => ['context_window' => 128000, 'max_output_tokens' => 16384],
                'status' => 'active',
                'enabled' => true,
                'customer_visible' => true,
            ]
        );

        // Create pricing for model aliases
        ModelPricing::query()->updateOrCreate(
            ['model_alias_id' => $geminiAlias->id],
            [
                'input_per_million_minor' => 50,
                'output_per_million_minor' => 150,
                'currency' => 'USD',
                'upstream_input_per_million_minor' => 20,
                'upstream_output_per_million_minor' => 80,
                'upstream_cost_verified_at' => now(),
            ]
        );

        ModelPricing::query()->updateOrCreate(
            ['model_alias_id' => $claudeAlias->id],
            [
                'input_per_million_minor' => 300,
                'output_per_million_minor' => 1500,
                'currency' => 'USD',
                'upstream_input_per_million_minor' => 150,
                'upstream_output_per_million_minor' => 750,
                'upstream_cost_verified_at' => now(),
            ]
        );

        ModelPricing::query()->updateOrCreate(
            ['model_alias_id' => $gptAlias->id],
            [
                'input_per_million_minor' => 500,
                'output_per_million_minor' => 1500,
                'currency' => 'USD',
                'upstream_input_per_million_minor' => 250,
                'upstream_output_per_million_minor' => 1000,
                'upstream_cost_verified_at' => now(),
            ]
        );

        // Create provider connection revision
        ProviderConnectionRevision::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'route_version' => 1],
            [
                'origin' => env('OMNIROUTE_BASE_URL', 'http://127.0.0.1:20128/v1'),
                'connection_type' => 'omniroute',
                'credential' => env('OMNIROUTE_API_KEY', 'test-secret'),
                'credential_suffix' => 'test',
                'timeout_ms' => 120000,
                'policy_version' => 1,
                'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
                'last_probe_status' => 'SUCCESS',
                'last_probe_at' => now(),
            ]
        );
    }
}
