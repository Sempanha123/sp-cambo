<?php

namespace App\Services\Payments;

use App\Contracts\KhqrGenerator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpKhqrGenerator implements KhqrGenerator
{
    public function generate(string $accountId, string $merchantName, string $merchantCity, string $currency, string $amountDecimal, string $reference): array
    {
        $url = (string) config('services.bakong.khqr_generator_url');
        $secret = (string) config('services.bakong.khqr_generator_secret');
        if ($url === '' || $secret === '') {
            throw new RuntimeException('KHQR generator service is not configured.');
        }
        $expiresAt = now()->addSeconds((int) config('services.bakong.attempt_ttl_seconds'))->getTimestampMs();
        $response = Http::acceptJson()->withToken($secret)->timeout(5)->post($url, ['account_id' => $accountId, 'merchant_name' => $merchantName, 'merchant_city' => $merchantCity, 'currency' => $currency, 'amount' => $amountDecimal, 'reference' => $reference, 'expires_at_unix_ms' => $expiresAt])->throw()->json();
        $payload = $response['data']['qr_payload'] ?? null;
        $md5 = $response['data']['md5'] ?? null;
        if (! is_string($payload) || ! is_string($md5) || ! preg_match('/^[a-f0-9]{32}$/i', $md5)) {
            throw new RuntimeException('KHQR generator returned an invalid response.');
        }

        return ['qr_payload' => $payload, 'md5' => strtolower($md5)];
    }
}
