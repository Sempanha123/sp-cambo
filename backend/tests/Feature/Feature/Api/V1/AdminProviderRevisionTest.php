<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminProviderRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    }

    public function test_admin_can_edit_unused_pending_revision_without_revealing_or_replacing_blank_credential(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)->putJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}", [
            'route_version' => 2,
            'origin' => 'https://draft-two.example/',
            'connection_type' => 'omniroute',
            'credential' => '',
            'timeout_ms' => 45000,
            'policy_version' => 2,
            'resolve_until' => null,
        ])->assertOk()
            ->assertJsonPath('data.route_version', 2)
            ->assertJsonPath('data.origin', 'https://draft-two.example')
            ->assertJsonPath('data.timeout_ms', 45000)
            ->assertJsonPath('data.policy_version', 2)
            ->assertJsonPath('data.credential_configured', true)
            ->assertJsonPath('data.credential_suffix', '••••cret')
            ->assertJsonMissingPath('data.credential');

        $revision->refresh();
        $this->assertSame('initial-secret', $revision->credential);
        $this->assertSame(2, $revision->route_version);
    }

    public function test_editing_a_ready_active_revision_verifies_a_replacement_and_moves_live_references(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_READY);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->saveOrFail();

        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'private-model',
            'display_name' => 'Private Model',
            'family' => 'test',
            'family_label' => 'Test',
            'capabilities' => [],
            'limits' => [],
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'public-model',
            'display_name' => 'Public Model',
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => false,
        ]);
        $pool = ModelRoutePool::query()->create([
            'model_alias_id' => $alias->id,
            'enabled' => true,
            'strategy' => ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            'max_failover_attempts' => 1,
            'circuit_failure_threshold' => 3,
            'circuit_cooldown_seconds' => 30,
        ]);
        $entry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
            'provider_connection_revision_id' => $revision->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 2,
            'priority' => 100,
        ]);

        Http::fake([
            'https://replacement.example/health' => Http::response(['error' => 'missing'], 404),
            'https://replacement.example/v1/models' => Http::response(['data' => [['id' => 'private-model']]], 200),
            'https://replacement.example/models' => Http::response(['data' => []], 200),
            'https://replacement.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_probe',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
            ], 200),
            'https://replacement.example/v1/messages' => Http::response(['error' => 'unsupported'], 400),
            'https://replacement.example/v1/responses' => Http::response(['error' => 'unsupported'], 400),
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}", [
                'route_version' => 1,
                'origin' => 'https://replacement.example/',
                'connection_type' => 'omniroute',
                'credential' => '',
                'timeout_ms' => 45000,
                'policy_version' => 2,
                'resolve_until' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.route_version', 2)
            ->assertJsonPath('data.origin', 'https://replacement.example')
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_READY)
            ->assertJsonPath('data.last_probe_status', 'SUCCESS')
            ->assertJsonPath('data.replacement_created', true)
            ->assertJsonPath('data.replaced_revision_id', $revision->id)
            ->assertJsonPath('data.moved_pool_entries', 1);

        $replacementId = $response->json('data.id');
        $replacement = ProviderConnectionRevision::query()->findOrFail($replacementId);

        $this->assertNotSame($revision->id, $replacement->id);
        $this->assertSame('initial-secret', $replacement->credential);
        $this->assertSame($replacement->id, $provider->refresh()->active_connection_revision_id);
        $this->assertSame($replacement->id, $entry->refresh()->provider_connection_revision_id);
        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->refresh()->lifecycle_status);
        $this->assertDatabaseMissing('provider_connection_revisions', [
            'provider_id' => $provider->id,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
        ]);
    }

    public function test_failed_live_revision_edit_keeps_the_existing_route_and_removes_the_failed_draft(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_READY);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->saveOrFail();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}", [
                'route_version' => 1,
                'origin' => 'https://broken-replacement.example',
                'connection_type' => 'omniroute',
                'credential' => '',
                'timeout_ms' => 30000,
                'policy_version' => 1,
                'resolve_until' => null,
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_connection_replacement_probe_failed');

        $this->assertSame($revision->id, $provider->refresh()->active_connection_revision_id);
        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->refresh()->lifecycle_status);
        $this->assertSame(1, ProviderConnectionRevision::query()->where('provider_id', $provider->id)->count());
        $this->assertDatabaseMissing('provider_connection_revisions', [
            'provider_id' => $provider->id,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
        ]);
    }

    public function test_admin_cannot_create_revision_with_an_ambiguous_origin_path(): void
    {
        $admin = $this->admin();
        [$provider] = $this->revision();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions", [
                'route_version' => 2,
                'origin' => 'https://provider.example/custom/path',
                'connection_type' => 'openai_compatible',
                'credential' => 'new-secret',
                'timeout_ms' => 30000,
                'policy_version' => 1,
                'resolve_until' => null,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_provider_origin');

        $this->assertDatabaseMissing('provider_connection_revisions', [
            'provider_id' => $provider->id,
            'route_version' => 2,
        ]);
    }

    public function test_successful_probe_promotes_and_auto_activates_the_first_pending_revision_and_audits_safe_metadata(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();
        Http::fake([
            'https://draft-one.example/health' => Http::response(['status' => 'ok']),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/probe")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_READY)
            ->assertJsonPath('data.last_probe_status', 'SUCCESS')
            ->assertJsonPath('data.probe_success', true)
            ->assertJsonPath('data.probe_endpoint_kind', 'health')
            ->assertJsonPath('data.auto_activated', true)
            ->assertJsonPath('data.active_connection_revision_id', $revision->id)
            ->assertJsonMissingPath('data.credential');

        $this->assertSame($revision->id, $provider->refresh()->active_connection_revision_id);
        $this->assertSame(ProviderConnectionRevision::STATUS_READY, $revision->refresh()->lifecycle_status);

        $audit = AuditLog::query()
            ->where('action', 'provider_connection_revision.probed')
            ->sole();
        $this->assertTrue($audit->metadata['success']);
        $this->assertTrue($audit->metadata['promoted_to_ready']);
        $this->assertTrue($audit->metadata['auto_activated']);
        $this->assertSame([['kind' => 'health', 'status' => 200]], $audit->metadata['attempts']);
        $this->assertArrayNotHasKey('credential', $audit->metadata);
        $this->assertArrayNotHasKey('origin', $audit->metadata);
    }

    public function test_health_success_alone_does_not_mark_a_configured_model_ready(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();
        AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'OpenAI Codex',
            'display_name' => 'OpenAI Codex',
            'family' => 'codex',
            'family_label' => 'OpenAI Codex',
            'capabilities' => [],
            'limits' => [],
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        Http::fake([
            'https://draft-one.example/health' => Http::response(['status' => 'ok'], 200),
            'https://draft-one.example/v1/models' => Http::response(['data' => [['id' => 'Different Model']]], 200),
            'https://draft-one.example/models' => Http::response(['data' => []], 200),
            'https://draft-one.example/v1/messages' => Http::response([
                'id' => 'msg_probe',
                'type' => 'message',
                'content' => [['type' => 'text', 'text' => 'OK']],
                'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/probe")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_READY)
            ->assertJsonPath('data.probe_endpoint_kind', 'messages');

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://draft-one.example/v1/messages'
            && $request['model'] === 'OpenAI Codex'
        );
    }

    public function test_probe_falls_back_to_a_tiny_messages_request_when_local_router_has_no_health_or_models_endpoint(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();
        AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'OpenAI Codex',
            'display_name' => 'OpenAI Codex',
            'family' => 'codex',
            'family_label' => 'OpenAI Codex',
            'capabilities' => [],
            'limits' => [],
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        Http::fake([
            'https://draft-one.example/health' => Http::response(['error' => 'missing'], 404),
            'https://draft-one.example/v1/models' => Http::response(['error' => 'missing'], 404),
            'https://draft-one.example/models' => Http::response(['error' => 'missing'], 404),
            'https://draft-one.example/v1/messages' => Http::response([
                'id' => 'msg_probe',
                'type' => 'message',
                'content' => [['type' => 'text', 'text' => 'OK']],
                'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/probe")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_READY)
            ->assertJsonPath('data.probe_endpoint_kind', 'messages')
            ->assertJsonPath('data.auto_activated', true);

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://draft-one.example/v1/messages'
            && $request['model'] === 'OpenAI Codex'
            && $request->hasHeader('x-api-key', 'initial-secret')
        );
    }

    public function test_failed_probe_does_not_promote_or_activate_revision(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/probe")
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_connection_probe_failed')
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_PENDING)
            ->assertJsonPath('data.last_probe_status', 'FAILED')
            ->assertJsonPath('data.probe_success', false)
            ->assertJsonMissingPath('data.credential');

        $this->assertNull($provider->refresh()->active_connection_revision_id);
        $this->assertSame(ProviderConnectionRevision::STATUS_PENDING, $revision->refresh()->lifecycle_status);
    }

    public function test_network_failure_returns_a_safe_probe_error(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();
        Http::fake(fn () => throw new \RuntimeException('secret-network-detail'));

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/probe")
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_connection_probe_failed')
            ->assertJsonMissing(['secret-network-detail']);

        $this->assertSame(ProviderConnectionRevision::STATUS_PENDING, $revision->refresh()->lifecycle_status);
    }

    public function test_admin_can_activate_a_ready_revision_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_READY);

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/providers/{$provider->id}/active-connection-revision", [
                'revision_id' => $revision->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.active_connection_revision_id', $revision->id);

        $this->assertSame($revision->id, $provider->refresh()->active_connection_revision_id);
        $audit = AuditLog::query()
            ->where('action', 'provider.active_connection_revision_changed')
            ->sole();
        $this->assertNull($audit->metadata['previous_revision_id']);
        $this->assertSame($revision->id, $audit->metadata['new_revision_id']);
    }

    public function test_admin_cannot_activate_a_pending_revision(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/providers/{$provider->id}/active-connection-revision", [
                'revision_id' => $revision->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'provider_revision_not_ready');

        $this->assertNull($provider->refresh()->active_connection_revision_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'provider.active_connection_revision_changed',
        ]);
    }

    public function test_admin_cannot_activate_another_providers_revision(): void
    {
        $admin = $this->admin();
        [$provider] = $this->revision();
        [, $foreignRevision] = $this->revision(ProviderConnectionRevision::STATUS_READY);

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/providers/{$provider->id}/active-connection-revision", [
                'revision_id' => $foreignRevision->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'provider_revision_not_owned');

        $this->assertNull($provider->refresh()->active_connection_revision_id);
    }

    public function test_manual_status_update_cannot_assign_ready(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/status", [
                'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
                'reason' => 'Attempting to bypass the required server-side probe.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame(ProviderConnectionRevision::STATUS_PENDING, $revision->refresh()->lifecycle_status);
    }

    public function test_ready_revision_can_be_drained_and_then_revoked_with_reasoned_audits(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_READY);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/status", [
                'lifecycle_status' => ProviderConnectionRevision::STATUS_DRAINING,
                'reason' => 'Rotating this connection to a replacement route.',
            ])
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_DRAINING);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/status", [
                'lifecycle_status' => ProviderConnectionRevision::STATUS_REVOKED,
                'reason' => 'The connection rotation has now been completed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', ProviderConnectionRevision::STATUS_REVOKED);

        $this->assertSame(2, AuditLog::query()
            ->where('action', 'provider_connection_revision.status_changed')
            ->count());
    }

    public function test_revoked_revision_cannot_transition_again(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_REVOKED);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}/status", [
                'lifecycle_status' => ProviderConnectionRevision::STATUS_DRAINING,
                'reason' => 'Attempting an invalid transition from a revoked route.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_provider_revision_transition');

        $this->assertSame(ProviderConnectionRevision::STATUS_REVOKED, $revision->refresh()->lifecycle_status);
    }

    public function test_admin_can_create_revision_without_manually_choosing_route_version(): void
    {
        $admin = $this->admin();
        [$provider] = $this->revision();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/connection-revisions", [
                'origin' => 'https://draft-two.example',
                'connection_type' => 'omniroute',
                'credential' => 'next-secret',
                'timeout_ms' => 30000,
                'policy_version' => 1,
                'resolve_until' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.route_version', 2);
    }

    public function test_removing_historical_revision_archives_it_instead_of_deleting_it(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision(ProviderConnectionRevision::STATUS_READY);

        Reservation::query()->create([
            'user_id' => User::factory()->create()->id,
            'provider_connection_revision_id' => $revision->id,
            'public_model_alias' => 'historical-model',
            'billing_mode' => 'TOKEN_QUOTA',
            'reserved_units' => 1,
            'status' => 'RELEASED',
            'idempotency_key' => 'historical-revision-remove-test',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}")
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.hidden', true)
            ->assertJsonPath('data.hard_deleted', false);

        $this->assertDatabaseHas('provider_connection_revisions', [
            'id' => $revision->id,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('reservations', [
            'provider_connection_revision_id' => $revision->id,
            'idempotency_key' => 'historical-revision-remove-test',
        ]);
    }

    public function test_admin_can_delete_unused_non_active_revision(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}")
            ->assertOk()->assertJsonPath('data.success', true);

        $this->assertDatabaseMissing('provider_connection_revisions', ['id' => $revision->id]);
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

    /** @return array{Provider, ProviderConnectionRevision} */
    private function revision(string $status = ProviderConnectionRevision::STATUS_PENDING): array
    {
        $provider = Provider::query()->create([
            'name' => 'Provider',
            'slug' => 'provider-'.uniqid(),
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://draft-one.example',
            'connection_type' => 'omniroute',
            'credential' => 'initial-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => $status,
        ]);

        return [$provider, $revision];
    }
}
