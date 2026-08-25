<?php

namespace Tests\Unit;

use App\Models\ProviderConnectionRevision;
use App\Services\ProviderEndpointService;
use Tests\TestCase;

class ProviderEndpointServiceTest extends TestCase
{
    public function test_root_origin_produces_health_and_compatible_model_candidates(): void
    {
        $revision = $this->revision('https://provider.example');
        $service = app(ProviderEndpointService::class);

        $this->assertSame([
            'https://provider.example/v1/models',
            'https://provider.example/models',
        ], $service->modelCatalogUrls($revision));
        $this->assertSame([
            ['url' => 'https://provider.example/health', 'kind' => 'health'],
            ['url' => 'https://provider.example/v1/models', 'kind' => 'models'],
            ['url' => 'https://provider.example/models', 'kind' => 'models'],
        ], $service->probeCandidates($revision));
    }

    public function test_v1_origin_does_not_produce_a_duplicate_v1_segment(): void
    {
        $revision = $this->revision('https://provider.example/v1');
        $service = app(ProviderEndpointService::class);

        $this->assertSame([
            'https://provider.example/v1/models',
            'https://provider.example/models',
        ], $service->modelCatalogUrls($revision));
        $this->assertSame([
            ['url' => 'https://provider.example/health', 'kind' => 'health'],
            ['url' => 'https://provider.example/v1/models', 'kind' => 'models'],
            ['url' => 'https://provider.example/models', 'kind' => 'models'],
        ], $service->probeCandidates($revision));
    }

    private function revision(string $origin): ProviderConnectionRevision
    {
        $revision = new ProviderConnectionRevision;
        $revision->forceFill(['origin' => $origin]);

        return $revision;
    }
}
