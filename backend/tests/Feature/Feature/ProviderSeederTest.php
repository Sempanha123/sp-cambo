<?php

namespace Tests\Feature\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
    }

    public function test_provider_seeder_is_idempotent_and_activates_its_ready_revision(): void
    {
        $this->seed(ProviderSeeder::class);
        $firstCiphertext = ProviderConnectionRevision::query()
            ->firstOrFail()
            ->getRawOriginal('credential');

        $this->seed(ProviderSeeder::class);

        $provider = Provider::query()->where('slug', 'omniroute')->sole();
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->where('route_version', 1)
            ->sole();

        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->lifecycle_status);
        $this->assertSame($revision->id, $provider->active_connection_revision_id);
        $this->assertSame(60000, $revision->timeout_ms);
        $this->assertSame('SUCCESS', $revision->last_probe_status);
        $this->assertSame($firstCiphertext, $revision->getRawOriginal('credential'));
        $this->assertDatabaseCount('provider_connection_revisions', 1);
    }
}
