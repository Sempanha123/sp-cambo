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
        $token = trim((string) config('services.bakong.token'));

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Bakong verification is not configured.');
        }

        if (! preg_match('/^[a-f0-9]{32}$/i', $md5)) {
            throw new RuntimeException('Bakong verification received an invalid KHQR digest.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(4)
            ->timeout(10)
            ->retry(2, 250, throw: false)
            ->post($baseUrl.'/v1/check_transaction_by_md5', ['md5' => strtolower($md5)]);

        // NBC documents HTTP 404 as a normal "transaction not found" outcome for
        // this endpoint. It is not an infrastructure failure and must stay
        // retryable while the QR is live. Authentication/quota/upstream errors are
        // operational failures and must never be misreported as "unpaid".
        if ($response->status() === 404) {
            return $this->notFound();
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('Bakong verification credentials were rejected (HTTP '.$response->status().').');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Bakong verification service is temporarily unavailable because the rate limit was reached (HTTP 429).');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Bakong verification service is temporarily unavailable (HTTP '.$response->status().').');
        }

        $raw = $response->body();
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Bakong verification returned an invalid response.');
        }

        $responseCode = $decoded['responseCode'] ?? null;
        $responseMessage = is_string($decoded['responseMessage'] ?? null)
            ? trim($decoded['responseMessage'])
            : '';
        $data = $decoded['data'] ?? null;

        // Bakong documents responseCode 1 for both "failed" and "not found".
        // Neither is proof of payment; both are safe to re-check while the QR is
        // live. Unknown non-success responses are treated as operational errors so
        // they do not masquerade as an unpaid transaction.
        if ((string) $responseCode !== '0') {
            $knownNegative = str_contains(strtolower($responseMessage), 'could not be found')
                || str_contains(strtolower($responseMessage), 'transaction failed');

            if ($knownNegative || in_array((int) ($decoded['errorCode'] ?? 0), [1, 3], true)) {
                return $this->notFound();
            }

            throw new RuntimeException('Bakong verification was rejected by the upstream service.');
        }

        if (! is_array($data)) {
            throw new RuntimeException('Bakong verification returned success without transaction evidence.');
        }

        // Preserve the exact lexical decimal when possible. This avoids binary
        // floating-point conversion before MoneyDecimal performs exact minor-unit
        // comparison. Fall back to the decoded scalar for response variants that
        // do not retain an ordinary JSON decimal token.
        preg_match('/"amount"\s*:\s*(?:"([0-9]+(?:\.[0-9]+)?)"|([0-9]+(?:\.[0-9]+)?))/', $raw, $matches);
        $amount = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? null);
        if (! is_string($amount)) {
            $amountValue = $data['amount'] ?? null;
            if (is_int($amountValue) || is_float($amountValue) || is_string($amountValue)) {
                $amount = (string) $amountValue;
            }
        }

        $hash = $data['hash'] ?? null;
        $toAccountId = $data['toAccountId'] ?? null;
        $currency = $data['currency'] ?? null;

        if (! is_string($amount)
            || ! is_string($hash) || trim($hash) === ''
            || ! is_string($toAccountId) || trim($toAccountId) === ''
            || ! is_string($currency) || trim($currency) === '') {
            throw new RuntimeException('Bakong verification returned incomplete evidence.');
        }

        return [
            'found' => true,
            'transaction_hash' => trim($hash),
            'to_account_id' => trim($toAccountId),
            'currency' => strtoupper(trim($currency)),
            'amount_decimal' => trim($amount),
        ];
    }

    private function notFound(): array
    {
        return [
            'found' => false,
            'transaction_hash' => null,
            'to_account_id' => null,
            'currency' => null,
            'amount_decimal' => null,
        ];
    }
}
