<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class KhqrQrImageClient
{
    public function render(string $qrPayload): string
    {
        $generatorUrl = trim((string) config('services.bakong.khqr_generator_url'));
        $secret = trim((string) config('services.bakong.khqr_generator_secret'));
        if ($generatorUrl === '' || $secret === '') {
            throw new RuntimeException('KHQR QR image renderer is not configured.');
        }

        $renderUrl = preg_replace('~/generate/?$~', '/render', $generatorUrl);
        if (! is_string($renderUrl) || $renderUrl === $generatorUrl) {
            throw new RuntimeException('KHQR generator URL must end with /generate.');
        }

        $response = Http::withToken($secret)
            ->accept('image/png')
            ->timeout(8)
            ->post($renderUrl, ['qr_payload' => $qrPayload]);

        if (! $response->successful()) {
            throw new RuntimeException('KHQR QR image renderer is temporarily unavailable.');
        }

        $png = $response->body();
        if (strlen($png) < 16 || strlen($png) > 2_000_000 || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('KHQR QR image renderer returned an invalid PNG.');
        }

        return $png;
    }
}
