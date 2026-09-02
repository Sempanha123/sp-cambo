<?php

namespace Tests\Unit;

use App\Services\Payments\KhqrQrImageClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KhqrQrImageClientTest extends TestCase
{
    public function test_renderer_uses_private_sidecar_and_returns_png(): void
    {
        config([
            'services.bakong.khqr_generator_url' => 'http://127.0.0.1:3011/v1/khqr/generate',
            'services.bakong.khqr_generator_secret' => 'private-test-secret',
        ]);

        $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 64);

        Http::fake([
            'http://127.0.0.1:3011/v1/khqr/render' => Http::response(
                $png,
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $this->assertSame($png, app(KhqrQrImageClient::class)->render(str_repeat('0', 40)));
        Http::assertSent(fn ($request) =>
            $request->url() === 'http://127.0.0.1:3011/v1/khqr/render'
            && $request->hasHeader('Authorization', 'Bearer private-test-secret')
            && $request['qr_payload'] === str_repeat('0', 40)
        );
    }
}
