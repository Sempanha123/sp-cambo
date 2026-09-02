<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_password_change_require_current_password_and_revoke_other_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Old!Password123']);
        $current = $user->createToken('current');
        $other = $user->createToken('other');
        $this->withToken($current->plainTextToken)->patchJson('/api/v1/me', ['name' => 'Updated Name'])->assertOk()->assertJsonPath('data.user.name', 'Updated Name');
        $this->withToken($current->plainTextToken)->postJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'New!Password123', 'password_confirmation' => 'New!Password123'])->assertUnprocessable();
        $this->withToken($current->plainTextToken)->postJson('/api/v1/me/password', ['current_password' => 'Old!Password123', 'password' => 'New!Password123', 'password_confirmation' => 'New!Password123'])->assertOk();
        $this->assertTrue(Hash::check('New!Password123', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_session_listing_and_revoke_are_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other');
        $otherUserToken = User::factory()->create()->createToken('foreign');
        $this->withToken($current->plainTextToken)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['name' => 'current', 'current' => true]);
        $this->withToken($current->plainTextToken)->deleteJson("/api/v1/me/sessions/{$otherUserToken->accessToken->id}")->assertNotFound();
        $this->withToken($current->plainTextToken)->deleteJson("/api/v1/me/sessions/{$other->accessToken->id}")->assertOk();
    }

    public function test_cookie_session_can_list_bearer_sessions_without_a_transient_token_error(): void
    {
        $user = User::factory()->create();
        $bearer = $user->createToken('other-device');
        $user->withAccessToken(new TransientToken);
        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => (string) $bearer->accessToken->id,
                'name' => 'other-device',
                'current' => false,
            ]);
    }

    public function test_password_change_from_cookie_session_revokes_all_bearer_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Old!Password123']);
        $user->createToken('other-device');
        $user->withAccessToken(new TransientToken);
        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/me/password', [
            'current_password' => 'Old!Password123',
            'password' => 'New!Password123',
            'password_confirmation' => 'New!Password123',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_forgot_password_is_neutral_and_reset_revokes_all_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test', 'password' => 'Old!Password123']);
        $user->createToken('one');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Reset!Password123', 'password_confirmation' => 'Reset!Password123'])->assertOk();
        $this->assertTrue(Hash::check('Reset!Password123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
