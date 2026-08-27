<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_service_redacts_secret_metadata_before_persistence(): void
    {
        $user = User::factory()->create();
        $secret = 'sk-spc-abcdefghijklmnop123456';

        app(AuditService::class)->record($user, 'security.test', 'test', '1', "rotated {$secret}", [
            'api_key' => $secret,
            'nested' => ['telegram_bot_token' => 'bot-secret-value', 'safe' => 'visible'],
            'message' => "accidentally pasted {$secret}",
        ]);

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('rotated [redacted]', $row->reason);
        $this->assertSame('[redacted]', $row->metadata['api_key']);
        $this->assertSame('[redacted]', $row->metadata['nested']['telegram_bot_token']);
        $this->assertSame('visible', $row->metadata['nested']['safe']);
        $this->assertSame('accidentally pasted [redacted]', $row->metadata['message']);
        $this->assertStringNotContainsString($secret, json_encode($row->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_api_response_has_safe_request_correlation_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'spcambo-test-1234')
            ->getJson('/api/v1/status');

        $response->assertHeader('X-Request-Id', 'spcambo-test-1234');
    }

    public function test_invalid_incoming_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-Id', "bad id\nunsafe")
            ->getJson('/api/v1/status');

        $requestId = $response->headers->get('X-Request-Id');
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]{8,64}$/i', $requestId);
        $this->assertNotSame("bad id\nunsafe", $requestId);
    }
}
