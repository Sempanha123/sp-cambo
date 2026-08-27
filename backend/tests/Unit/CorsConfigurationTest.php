<?php

namespace Tests\Unit;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_browser_write_headers_required_by_checkout_are_allowed(): void
    {
        $headers = array_map('strtolower', config('cors.allowed_headers', []));

        $this->assertContains('idempotency-key', $headers);
        $this->assertContains('x-request-id', $headers);
        $this->assertContains('x-xsrf-token', $headers);
        $this->assertContains('x-request-id', array_map('strtolower', config('cors.exposed_headers', [])));
    }

    public function test_credentialed_cors_does_not_use_wildcard_origin(): void
    {
        $this->assertTrue((bool) config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins', []));
    }
}
