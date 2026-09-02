<?php

namespace Tests\Feature\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ProviderConnectionRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_pending_revision_routing_fields_are_editable(): void
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider', 'enabled' => true]);
        $revision = $this->revision($provider);

        $revision->update([
            'route_version' => 2,
            'origin' => 'https://new-provider.example',
            'connection_type' => 'openai_compatible',
            'credential' => 'replacementcredential1234',
            'credential_suffix' => '1234',
            'timeout_ms' => 20_000,
            'policy_version' => 2,
        ]);

        $this->assertSame(2, $revision->fresh()->route_version);
        $this->assertSame('https://new-provider.example', $revision->fresh()->origin);
    }

    public function test_ready_revision_routing_fields_remain_immutable(): void
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider-ready', 'enabled' => true]);
        $revision = $this->revision($provider, ProviderConnectionRevision::STATUS_READY);

        $this->expectException(LogicException::class);
        $revision->update(['origin' => 'https://mutated.example']);
    }

    public function test_active_pending_revision_routing_fields_remain_immutable(): void
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider-active', 'enabled' => true]);
        $revision = $this->revision($provider);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();

        $this->expectException(LogicException::class);
        $revision->update(['origin' => 'https://mutated.example']);
    }

    private function revision(Provider $provider, string $status = ProviderConnectionRevision::STATUS_PENDING): ProviderConnectionRevision
    {
        return ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://provider.example',
            'connection_type' => 'omniroute',
            'credential' => 'providercredential5678',
            'credential_suffix' => '5678',
            'timeout_ms' => 15_000,
            'policy_version' => 1,
            'lifecycle_status' => $status,
        ]);
    }
}
