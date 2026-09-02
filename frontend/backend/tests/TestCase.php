<?php

namespace Tests;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function publishPackage(Package $package): ModelAlias
    {
        $suffix = strtolower((string) Str::ulid());
        $provider = Provider::query()->create([
            'name' => "Test Provider {$suffix}",
            'slug' => "test-provider-{$suffix}",
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://127.0.0.1:3010',
            'connection_type' => 'omniroute',
            'credential' => 'test-provider-credential',
            'credential_suffix' => 'tial',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => "test/model-{$suffix}",
            'family' => $package->family,
            'family_label' => $package->family_label,
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => "test-model-{$suffix}",
            'display_name' => 'Test Model',
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $package->modelAliases()->attach($alias);

        return $alias;
    }
}
