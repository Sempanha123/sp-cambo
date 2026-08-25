<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\User;
use App\Services\ApiKeySecretService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_revealed_only_on_create_and_never_stored_or_listed(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $created = $this->actingAs($user)->postJson('/api/v1/me/api-keys', ['label' => 'CLI', 'allowed_model_aliases' => [$alias->public_alias]])->assertCreated();
        $secret = $created->json('data.secret');
        $this->assertStringStartsWith('sk-spc-', $secret);
        $this->assertDatabaseMissing('api_keys', ['lookup_digest' => $secret]);
        $this->assertSame(app(ApiKeySecretService::class)->digest($secret), $created->json('data.key') ? $user->apiKeys()->value('lookup_digest') : null);
        $this->actingAs($user)->getJson('/api/v1/me/api-keys')->assertOk()->assertJsonMissingPath('data.0.secret')->assertJsonPath('data.0.last_four', substr($secret, -4));
    }

    public function test_rotation_invalidates_old_digest_and_revocation_is_terminal(): void
    {
        $user = User::factory()->create();
        $this->alias();
        $old = $this->actingAs($user)->postJson('/api/v1/me/api-keys', ['label' => 'CLI'])->json('data.secret');
        $key = $user->apiKeys()->firstOrFail();
        $new = $this->actingAs($user)->postJson("/api/v1/me/api-keys/{$key->id}/rotate")->assertOk()->json('data.secret');
        $this->assertNotSame($old, $new);
        $this->assertDatabaseMissing('api_keys', ['lookup_digest' => app(ApiKeySecretService::class)->digest($old)]);
        $this->actingAs($user)->patchJson("/api/v1/me/api-keys/{$key->id}/status", ['status' => 'REVOKED'])->assertOk();
        $this->actingAs($user)->patchJson("/api/v1/me/api-keys/{$key->id}/status", ['status' => 'ACTIVE'])->assertStatus(409)->assertJsonPath('code', 'api_key_revoked');
        $this->actingAs($user)->postJson("/api/v1/me/api-keys/{$key->id}/rotate")->assertStatus(409)->assertJsonPath('code', 'api_key_revoked');
    }

    public function test_keys_are_tenant_isolated_and_status_is_non_billable(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $this->alias();
        $this->actingAs($owner)->postJson('/api/v1/me/api-keys', ['label' => 'Owned']);
        $key = $owner->apiKeys()->firstOrFail();
        $this->actingAs($attacker)->getJson("/api/v1/me/api-keys/{$key->id}/status")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/me/api-keys/{$key->id}/status")->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.token_quota_remaining', null);
        $this->assertDatabaseCount('api_keys', 1);
    }

    private function alias(): ModelAlias
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

        return ModelAlias::query()->create(['ai_model_id' => $model->id, 'public_alias' => 'claude-coding', 'display_name' => 'Claude Coding', 'capabilities' => ['messages_api' => true], 'limits' => [], 'status' => 'active', 'enabled' => true, 'customer_visible' => true]);
    }
}
