<?php

namespace Tests\Feature\Feature;

use App\Events\CustomerStateChanged;
use App\Models\CreditLedger;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_customer_channel_is_tenant_isolated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerToken = $owner->createToken('reverb')->plainTextToken;
        $otherToken = $other->createToken('reverb')->plainTextToken;
        $this->withToken($ownerToken)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-users.{$owner->id}"])->assertOk();
        auth()->forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-users.{$owner->id}"])->assertForbidden();
    }

    public function test_broadcast_payload_contains_only_explicit_safe_metadata_and_rest_version(): void
    {
        $user = User::factory()->create();
        CreditLedger::query()->create(['user_id' => $user->id, 'type' => 'ADMIN_GRANT', 'amount' => 30, 'idempotency_key' => 'realtime-version-1']);
        $event = new CustomerStateChanged((int) $user->id, 'usage.settled', ['public_model' => 'claude-coding', 'metered_units' => '30']);
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-users.{$user->id}", $channels[0]->name);
        $eventPayload = $event->broadcastWith();
        $this->assertSame((int) CreditLedger::query()->max('id'), $eventPayload['version']);
        $payload = json_encode($eventPayload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('authorization', strtolower($payload));
        $this->assertStringNotContainsString('prompt', strtolower($payload));
        $this->assertStringNotContainsString('secret', strtolower($payload));
    }
}
