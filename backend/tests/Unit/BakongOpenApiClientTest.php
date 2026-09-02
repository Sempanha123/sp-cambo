<?php

namespace Tests\Unit;

use App\Services\Payments\BakongOpenApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BakongOpenApiClientTest extends TestCase
{
    public function test_official_md5_endpoint_and_exact_lexical_amount_are_used(): void
    {
        config(['services.bakong.base_url' => 'https://bakong.test', 'services.bakong.token' => 'test-token-placeholder']);
        Http::fake(['https://bakong.test/v1/check_transaction_by_md5' => Http::response('{"responseCode":0,"data":{"hash":"'.str_repeat('a', 64).'","fromAccountId":"payer@bank","toAccountId":"merchant@bank","currency":"USD","amount":1.50}}', 200, ['Content-Type' => 'application/json'])]);

        $result = app(BakongOpenApiClient::class)->checkByMd5(str_repeat('b', 32));

        $this->assertTrue($result['found']);
        $this->assertSame('1.50', $result['amount_decimal']);
        $this->assertSame('merchant@bank', $result['to_account_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://bakong.test/v1/check_transaction_by_md5' && $request->hasHeader('Authorization', 'Bearer test-token-placeholder') && $request['md5'] === str_repeat('b', 32));
    }


    public function test_string_zero_response_code_is_accepted_and_evidence_is_normalized(): void
    {
        config(['services.bakong.base_url' => 'https://bakong.test', 'services.bakong.token' => 'test-token-placeholder']);
        Http::fake(['*' => Http::response([
            'responseCode' => '0',
            'responseMessage' => 'Getting transaction successfully.',
            'data' => [
                'hash' => str_repeat('C', 64),
                'toAccountId' => ' Merchant@Bank ',
                'currency' => 'usd',
                'amount' => '0.01',
            ],
        ])]);

        $result = app(BakongOpenApiClient::class)->checkByMd5(str_repeat('b', 32));

        $this->assertTrue($result['found']);
        $this->assertSame('Merchant@Bank', $result['to_account_id']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('0.01', $result['amount_decimal']);
    }

    public function test_http_quota_or_auth_failure_is_not_misreported_as_unpaid(): void
    {
        config(['services.bakong.base_url' => 'https://bakong.test', 'services.bakong.token' => 'test-token-placeholder']);
        Http::fake(['*' => Http::response(['message' => 'rate limited'], 429)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporarily unavailable');

        app(BakongOpenApiClient::class)->checkByMd5(str_repeat('b', 32));
    }

    public function test_http_404_is_treated_as_retryable_transaction_not_found(): void
    {
        config(['services.bakong.base_url' => 'https://bakong.test', 'services.bakong.token' => 'test-token-placeholder']);
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $this->assertFalse(app(BakongOpenApiClient::class)->checkByMd5(str_repeat('b', 32))['found']);
    }

    public function test_not_found_response_is_non_authoritative_and_safe_to_retry(): void
    {
        config(['services.bakong.base_url' => 'https://bakong.test', 'services.bakong.token' => 'test-token-placeholder']);
        Http::fake(['*' => Http::response(['responseCode' => 1, 'errorCode' => 1, 'data' => null], 200)]);

        $this->assertFalse(app(BakongOpenApiClient::class)->checkByMd5(str_repeat('b', 32))['found']);
    }

    public function test_approved_pool_advances_after_bakong_daily_limit_response(): void
    {
        config([
            'services.bakong.base_url' => 'https://bakong.test',
            'services.bakong.token' => null,
            'services.bakong.tokens' => ['approved-token-one', 'approved-token-two'],
            'services.bakong.token_daily_limit' => 100,
            'services.bakong.quota_timezone' => 'Asia/Phnom_Penh',
        ]);

        $authorization = [];
        Http::fake(function (Request $request) use (&$authorization) {
            $authorization[] = $request->header('Authorization')[0] ?? null;

            return count($authorization) === 1
                ? Http::response([
                    'responseCode' => 1,
                    'responseMessage' => 'Daily request limit of 100 exceeded. Please try again tomorrow.',
                    'errorCode' => 17,
                ])
                : Http::response(['message' => 'not found'], 404);
        });

        $result = app(BakongOpenApiClient::class)->checkByMd5(str_repeat('c', 32));

        $this->assertFalse($result['found']);
        $this->assertSame([
            'Bearer approved-token-one',
            'Bearer approved-token-two',
        ], $authorization);
        Http::assertSentCount(2);
    }

    public function test_local_daily_counter_advances_to_next_approved_token_atomically(): void
    {
        config([
            'services.bakong.base_url' => 'https://bakong.test',
            'services.bakong.token' => null,
            'services.bakong.tokens' => ['one-request-token', 'next-request-token'],
            'services.bakong.token_daily_limit' => 1,
            'services.bakong.quota_timezone' => 'Asia/Phnom_Penh',
        ]);

        $authorization = [];
        Http::fake(function (Request $request) use (&$authorization) {
            $authorization[] = $request->header('Authorization')[0] ?? null;

            return Http::response(['message' => 'not found'], 404);
        });

        $client = app(BakongOpenApiClient::class);
        $this->assertFalse($client->checkByMd5(str_repeat('d', 32))['found']);
        $this->assertFalse($client->checkByMd5(str_repeat('e', 32))['found']);

        $this->assertSame([
            'Bearer one-request-token',
            'Bearer next-request-token',
        ], $authorization);
        Http::assertSentCount(2);
    }

    public function test_authentication_failure_does_not_advance_to_another_token(): void
    {
        config([
            'services.bakong.base_url' => 'https://bakong.test',
            'services.bakong.token' => null,
            'services.bakong.tokens' => ['invalid-token', 'untouched-token'],
            'services.bakong.token_daily_limit' => 100,
            'services.bakong.quota_timezone' => 'Asia/Phnom_Penh',
        ]);
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        try {
            app(BakongOpenApiClient::class)->checkByMd5(str_repeat('f', 32));
            $this->fail('An authentication failure must be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('credentials were rejected', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer invalid-token'));
    }
}
