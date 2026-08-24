<?php

namespace App\Services\Payments;

use App\Contracts\BakongVerifier;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BakongOpenApiClient implements BakongVerifier
{
    public function checkByMd5(string $md5): array
    {
        $baseUrl = rtrim((string) config('services.bakong.base_url'), '/');
        $token = (string) config('services.bakong.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Bakong verification is not configured.');
        }
        $response = Http::acceptJson()->withToken($token)->timeout(10)->retry(2, 200)->post($baseUrl.'/v1/check_transaction_by_md5', ['md5' => $md5])->throw();
        $raw = $response->body();
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (($decoded['responseCode'] ?? null) !== 0 || ! is_array($decoded['data'] ?? null)) {
            return ['found' => false, 'transaction_hash' => null, 'to_account_id' => null, 'currency' => null, 'amount_decimal' => null];
        }
        preg_match('/"amount"\s*:\s*(?:"([0-9]+(?:\.[0-9]+)?)"|([0-9]+(?:\.[0-9]+)?))/', $raw, $matches);
        $amount = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? null);
        $data = $decoded['data'];
        if (! is_string($amount) || ! is_string($data['hash'] ?? null) || ! is_string($data['toAccountId'] ?? null) || ! is_string($data['currency'] ?? null)) {
            throw new RuntimeException('Bakong verification returned incomplete evidence.');
        }

        return ['found' => true, 'transaction_hash' => $data['hash'], 'to_account_id' => $data['toAccountId'], 'currency' => $data['currency'], 'amount_decimal' => $amount];
    }
}
