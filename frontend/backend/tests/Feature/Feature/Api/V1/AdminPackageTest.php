<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ModelAlias;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_management_requires_explicit_catalog_permission(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/packages')->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_admin_model_alias_index_exposes_ids_and_exact_pricing_without_private_routes(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/model-aliases')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($admin)->getJson('/api/v1/admin/model-aliases')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $alias->id)
            ->assertJsonPath('data.0.public_alias', 'claude-coding')
            ->assertJsonPath('data.0.display_name', 'Claude Coding')
            ->assertJsonPath('data.0.sell.input_per_million_minor', '200')
            ->assertJsonPath('data.0.upstream_cost.input_per_million_minor', '100')
            ->assertJsonMissingPath('data.0.ai_model_id')
            ->assertJsonMissingPath('data.0.internal_model_id')
            ->assertJsonMissingPath('data.0.provider');
    }

    public function test_admin_package_response_round_trips_every_required_write_field(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $payload = $this->payload($alias, [
            'subtitle' => 'Operator subtitle',
            'badge' => 'Best value',
            'compare_at_price_minor' => 250,
            'auto_creates_api_key' => true,
            'featured' => true,
            'sort_order' => 42,
            'starts_at' => now()->addHour()->startOfSecond()->toAtomString(),
            'ends_at' => now()->addDay()->startOfSecond()->toAtomString(),
        ]);

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/packages', $payload)->assertCreated()->json('data');

        foreach (['subtitle', 'badge', 'family', 'family_label', 'unit_label', 'auto_creates_api_key', 'featured', 'sort_order', 'starts_at', 'ends_at'] as $field) {
            $this->assertArrayHasKey($field, $created);
        }
        $this->assertSame('250', $created['compare_at_price_minor']);
        $this->assertSame([(int) $alias->id], $created['allowed_model_alias_ids']);

        $replacement = array_intersect_key($created, array_flip([
            'slug', 'name', 'subtitle', 'badge', 'billing_mode', 'family', 'family_label',
            'advertised_units', 'unit_label', 'price_minor', 'compare_at_price_minor', 'currency',
            'currency_exponent', 'duration_seconds', 'limits', 'billing_rules', 'auto_creates_api_key',
            'featured', 'sort_order', 'starts_at', 'ends_at', 'enabled', 'customer_visible',
            'minimum_margin_bps', 'profitability_override_reason', 'allowed_model_alias_ids',
        ]));
        $replacement['name'] = 'Round-tripped Package';

        $this->actingAs($admin)->putJson("/api/v1/admin/packages/{$created['id']}", $replacement)
            ->assertOk()
            ->assertJsonPath('data.name', 'Round-tripped Package')
            ->assertJsonPath('data.subtitle', 'Operator subtitle')
            ->assertJsonPath('data.allowed_model_alias_ids.0', $alias->id);
    }

    public function test_profitable_package_can_be_published_with_exact_integer_analysis(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/packages', $this->payload($alias, ['price_minor' => 200, 'minimum_margin_bps' => 4000]))->assertCreated();

        $response->assertJsonPath('data.profitability.worst_case_cost_minor', '100')
            ->assertJsonPath('data.profitability.margin_minor', '100')
            ->assertJsonPath('data.profitability.margin_bps', 5000)
            ->assertJsonPath('data.profitability.profitable', true);
        $this->getJson('/api/v1/catalog/packages')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unreviewed_or_below_floor_package_fails_closed_without_explicit_override(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $this->actingAs($admin)->postJson('/api/v1/admin/packages', $this->payload($alias, ['price_minor' => 100, 'minimum_margin_bps' => 1000]))
            ->assertStatus(409)->assertJsonPath('code', 'profitability_review_required');
        $this->assertDatabaseMissing('packages', ['slug' => 'admin-package']);
    }

    public function test_authorized_override_reason_is_recorded_and_allows_publication(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $reason = 'Approved launch campaign with a time-limited acquisition budget.';
        $this->actingAs($admin)->postJson('/api/v1/admin/packages', $this->payload($alias, ['price_minor' => 100, 'minimum_margin_bps' => 1000, 'profitability_override_reason' => $reason]))
            ->assertCreated()->assertJsonPath('data.profitability.override_required', true)->assertJsonPath('data.profitability_override_reason', $reason);
        $this->assertDatabaseHas('packages', ['slug' => 'admin-package', 'profitability_override_reason' => $reason]);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'package.created', 'reason' => $reason]);
    }

    public function test_admin_can_update_exact_sell_and_upstream_pricing_with_audit_reason(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $reason = 'Quarterly provider price verification completed.';

        $this->actingAs($admin)->putJson("/api/v1/admin/model-aliases/{$alias->id}/pricing", ['currency' => 'USD', 'exponent' => 2, 'input_per_million_minor' => 250, 'output_per_million_minor' => 600, 'cache_read_per_million_minor' => null, 'cache_write_per_million_minor' => null, 'upstream_input_per_million_minor' => 110, 'upstream_output_per_million_minor' => 190, 'upstream_cache_read_per_million_minor' => null, 'upstream_cache_write_per_million_minor' => null, 'upstream_cost_verified_at' => now()->toAtomString(), 'reason' => $reason])
            ->assertOk()->assertJsonPath('data.sell.input_per_million_minor', '250')->assertJsonPath('data.upstream_cost.output_per_million_minor', '190');
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'model_pricing.updated', 'reason' => $reason]);
    }

    public function test_audit_log_is_immutable(): void
    {
        $admin = $this->admin();
        $alias = $this->pricedAlias(100);
        $this->actingAs($admin)->postJson('/api/v1/admin/packages', $this->payload($alias))->assertCreated();

        $this->expectException(\LogicException::class);
        AuditLog::query()->firstOrFail()->update(['action' => 'tampered']);
    }

    private function admin(): User
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'catalog.manage'], ['label' => 'Manage catalog']);
        $role = Role::query()->firstOrCreate(['name' => 'ADMIN'], ['label' => 'Administrator']);
        $role->permissions()->syncWithoutDetaching($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function pricedAlias(int $upstreamCost): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider', 'enabled' => true]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://127.0.0.1:3010',
            'connection_type' => 'omniroute',
            'credential' => 'test-provider-credential',
            'credential_suffix' => 'test',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create(['provider_id' => $provider->id, 'internal_model_id' => 'private', 'family' => 'claude', 'family_label' => 'Claude', 'commercial_resale_verified_at' => now(), 'enabled' => true]);
        $alias = ModelAlias::query()->create(['ai_model_id' => $model->id, 'public_alias' => 'claude-coding', 'display_name' => 'Claude Coding', 'capabilities' => [], 'limits' => [], 'status' => 'active', 'enabled' => true, 'customer_visible' => true]);
        $alias->pricing()->create(['currency' => 'USD', 'exponent' => 2, 'input_per_million_minor' => 200, 'output_per_million_minor' => 500, 'upstream_input_per_million_minor' => $upstreamCost, 'upstream_output_per_million_minor' => $upstreamCost, 'upstream_cost_verified_at' => now()]);

        return $alias;
    }

    private function payload(ModelAlias $alias, array $overrides = []): array
    {
        return $overrides + ['slug' => 'admin-package', 'name' => 'Admin Package', 'subtitle' => null, 'badge' => null, 'billing_mode' => 'TOKEN_QUOTA', 'family' => 'claude', 'family_label' => 'Claude', 'advertised_units' => 1_000_000, 'unit_label' => 'tokens', 'price_minor' => 200, 'compare_at_price_minor' => null, 'currency' => 'USD', 'currency_exponent' => 2, 'duration_seconds' => 86400, 'limits' => [], 'auto_creates_api_key' => false, 'featured' => false, 'sort_order' => 10, 'starts_at' => null, 'ends_at' => null, 'enabled' => true, 'customer_visible' => true, 'minimum_margin_bps' => 4000, 'profitability_override_reason' => null, 'allowed_model_alias_ids' => [$alias->id]];
    }
}
