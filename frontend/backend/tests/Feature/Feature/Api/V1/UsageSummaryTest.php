<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_usage_window_is_zero_filled_in_chronological_utc_buckets(): void
    {
        $this->travelTo(now()->utc()->startOfHour()->addMinutes(30));
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/v1/me/usage/summary?bucket=hour&from='.urlencode(now()->subHours(3)->toAtomString()).'&to='.urlencode(now()->toAtomString()))->assertOk()->assertJsonCount(4, 'data.buckets')->assertJsonPath('data.requests', 0)->assertJsonPath('data.metered_units', '0')->assertJsonPath('data.saved_tokens', '0')->assertJsonPath('data.billed_tokens', '0')->assertJsonPath('data.savings_rate_percent', 0)->assertJsonPath('data.credits_saved', '0.00000')->assertJsonPath('data.sp_credits_used', '0.00000');
        $buckets = $response->json('data.buckets');
        $this->assertSame(array_values($buckets), collect($buckets)->sortBy('at')->values()->all());
        foreach ($buckets as $bucket) {
            $this->assertSame(0, $bucket['requests']);
            $this->assertSame('0', $bucket['saved_tokens']);
            $this->assertSame('0', $bucket['billed_tokens']);
            $this->assertSame(0, $bucket['savings_rate_percent']);
            $this->assertSame('0', $bucket['metered_units']);
            $this->assertStringContainsString('+00:00', $bucket['at']);
        }
    }
}
