<?php

namespace Tests\Feature\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\ProviderProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderProbeProtocolDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_catalog_presence_does_not_guess_protocol_and_real_inference_selects_responses(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);

        $provider = Provider::query()->create([
            'slug' => 'probe-test',
            'name' => 'Probe Test',
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://router.test',
            'connection_type' => 'omniroute',
            'credential' => 'private-test-token',
            'credential_suffix' => 'oken',
            'timeout_ms' => 5000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === 'http://router.test/health') {
                return Http::response(['status' => 'ok'], 200);
            }
            if ($url === 'http://router.test/v1/models') {
                return Http::response(['data' => [['id' => 'OpenAI Codex']]], 200);
            }
            if ($url === 'http://router.test/models') {
                return Http::response([], 404);
            }
            if ($url === 'http://router.test/v1/responses') {
                return Http::response(['id' => 'resp_test'], 200);
            }

            return Http::response(['error' => 'unsupported'], 404);
        });

        $result = app(ProviderProbeService::class)->probe($revision, 'OpenAI Codex');

        $this->assertTrue($result['success']);
        $this->assertSame('responses', $result['endpoint_kind']);
        $this->assertContains(['kind' => 'models', 'status' => 200], $result['attempts']);
        $this->assertContains(['kind' => 'responses', 'status' => 200], $result['attempts']);
    }
}
