<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Support\AccessAllocationSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAllocationSchemaReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_database_schema_contains_the_access_allocation_columns_required_by_customer_pages(): void
    {
        $this->assertTrue(AccessAllocationSchema::ready());
        $this->assertSame([], AccessAllocationSchema::missing());
        $this->artisan('system:check-access-allocation-schema')->assertSuccessful();
    }
}
