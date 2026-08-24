<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_is_measured_and_contains_only_customer_safe_components(): void
    {
        config([
            'services.bakong.token' => 'must-never-appear',
            'services.spcambo.gateway_secret' => 'must-never-appear',
            'services.omniroute.base_url' => 'http://private-omniroute:20128',
        ]);
        SystemHeartbeat::query()->create(['component' => 'scheduler', 'recorded_at' => now()]);

        $response = $this->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('data.overall', 'degraded')
            ->assertJsonPath('data.components.0.key', 'control_plane')
            ->assertJsonPath('data.components.0.status', 'operational')
            ->assertJsonPath('data.components.1.key', 'inference_api')
            ->assertJsonPath('data.components.1.status', 'maintenance')
            ->assertJsonPath('data.components.2.key', 'payments')
            ->assertJsonPath('data.components.2.status', 'maintenance');

        foreach (['localhost', 'OmniRoute', 'private-omniroute', '20128', 'must-never-appear', 'database', 'queue', 'scheduler', 'failed job'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $response->getContent(), '', true);
        }
    }

    public function test_public_status_changes_when_measured_queue_or_scheduler_is_unhealthy(): void
    {
        SystemHeartbeat::query()->create(['component' => 'scheduler', 'recorded_at' => now()->subMinutes(10)]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-status-test',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'private stack trace',
            'failed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('data.overall', 'degraded')
            ->assertJsonPath('data.components.0.key', 'control_plane')
            ->assertJsonPath('data.components.0.status', 'degraded')
            ->assertJsonPath('data.components.0.detail', 'This service is currently degraded.');

        $this->assertStringNotContainsString('private stack trace', $response->getContent());
        $this->assertStringNotContainsString('failed-status-test', $response->getContent());
    }
}
