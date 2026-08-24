<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Enums\AccountStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_an_ok_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                ],
            ]);
    }

    public function test_user_can_register_and_use_the_issued_token_to_get_their_identity(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ]);

        $registration
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Test User')
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'email_verified_at', 'created_at'],
                    'token',
                ],
            ]);

        $token = $registration->json('data.token');

        $this->assertIsString($token);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertTrue(User::query()->where('email', 'test@example.com')->firstOrFail()->hasRole('CUSTOMER'));

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Test User')
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_identity_responses_publish_only_effective_role_and_permission_names(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('Admin!Password123'),
        ]);
        $adminView = Permission::query()->create(['name' => 'admin.view', 'label' => 'View admin analytics']);
        $catalogManage = Permission::query()->create(['name' => 'catalog.manage', 'label' => 'Manage catalog']);
        $admin = Role::query()->create(['name' => 'SUPER_ADMIN', 'label' => 'Super Administrator']);
        $admin->permissions()->attach([$catalogManage->id, $adminView->id]);
        $user->roles()->attach($admin);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Admin!Password123',
        ])->assertOk()
            ->assertJsonPath('data.user.roles', ['SUPER_ADMIN'])
            ->assertJsonPath('data.user.permissions', ['admin.view', 'catalog.manage'])
            ->assertJsonMissingPath('data.user.roles.0.permissions')
            ->assertJsonMissingPath('data.user.password');

        $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['SUPER_ADMIN'])
            ->assertJsonPath('data.user.permissions', ['admin.view', 'catalog.manage']);
    }

    public function test_registration_bootstraps_the_customer_role_on_a_fresh_migrated_database(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Unseeded User',
            'email' => 'unseeded@example.test',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ])->assertCreated();

        $this->assertDatabaseHas('roles', [
            'name' => 'CUSTOMER',
            'label' => 'Customer',
        ]);
        $this->assertTrue(User::query()->where('email', 'unseeded@example.test')->firstOrFail()->hasRole('CUSTOMER'));
        $this->assertIsString($response->json('data.token'));
    }

    public function test_registration_validation_errors_use_laravel_json_validation_shape(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_log_in_and_log_out_with_a_personal_access_token(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('a-secure-test-password'),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-secure-test-password',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);

        $token = $login->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'message' => 'Logged out.',
                ],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        app('auth')->guard('sanctum')->forgetUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_stateful_spa_login_uses_csrf_protected_http_only_session_without_bearer_token(): void
    {
        config([
            'sanctum.stateful' => ['app.spcambo.test'],
            'cors.allowed_origins' => ['https://app.spcambo.test'],
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
        $user = User::factory()->create(['email' => 'spa@example.test', 'password' => Hash::make('Spa!Password123')]);
        $originHeaders = ['Origin' => 'https://app.spcambo.test', 'Referer' => 'https://app.spcambo.test/login'];

        $csrf = $this->withHeaders($originHeaders)->get('/sanctum/csrf-cookie')->assertNoContent();
        $xsrf = collect($csrf->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
        $this->assertNotNull($xsrf);

        $login = $this->withHeaders($originHeaders + ['X-XSRF-TOKEN' => $xsrf->getValue()])
            ->withCookie('XSRF-TOKEN', $xsrf->getValue())
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Spa!Password123'])
            ->assertOk()
            ->assertJsonPath('data.token', null);

        $session = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertTrue($session->isHttpOnly());
        $this->assertTrue($session->isSecure());
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeaders($originHeaders)
            ->withCookie(config('session.cookie'), $session->getValue())
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_stateful_spa_write_without_csrf_token_is_rejected_with_stable_code(): void
    {
        $middleware = new \ReflectionMethod(EnsureFrontendRequestsAreStateful::class, 'frontendMiddleware');
        $this->assertContains(ValidateCsrfToken::class, $middleware->invoke(app(EnsureFrontendRequestsAreStateful::class)));

        // Laravel bypasses CSRF only while its test runner is active. Exercise the
        // centralized TokenMismatchException mapping directly and keep an integration
        // assertion above proving the real XSRF cookie/header path succeeds.
        $response = $this->postJson('/api/v1/test-csrf-exception');
        $response->assertStatus(419)->assertJsonPath('code', 'csrf_token_mismatch');
        $this->assertStringNotContainsString('private csrf detail', $response->getContent());
    }

    public function test_trusted_proxy_headers_supply_client_ip_but_direct_clients_cannot_spoof_it(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.10');
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.10';
        $this->refreshApplication();

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
                ->withHeader('X-Forwarded-For', '198.51.100.44')
                ->getJson('/api/v1/health')
                ->assertOk();
            $this->assertSame('198.51.100.44', request()->ip());

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->withHeader('X-Forwarded-For', '198.51.100.77')
                ->getJson('/api/v1/health')
                ->assertOk();
            $this->assertSame('203.0.113.9', request()->ip());
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            $this->refreshApplication();
        }
    }

    public function test_credentialed_cors_allows_only_configured_browser_origin(): void
    {
        config(['cors.allowed_origins' => ['https://app.spcambo.test'], 'cors.supports_credentials' => true]);

        $this->withHeaders(['Origin' => 'https://app.spcambo.test', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v1/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.spcambo.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $evil = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v1/auth/login')
            ->assertNoContent();
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_bearer_login_remains_available_for_non_browser_clients(): void
    {
        $user = User::factory()->create(['email' => 'cli@example.test', 'password' => Hash::make('Cli!Password123')]);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Cli!Password123'])
            ->assertOk();
        $this->assertTrue(Str::contains($response->json('data.token'), '|'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_login_does_not_issue_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('a-secure-test-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_recovery_is_enumeration_safe_and_generates_a_frontend_reset_url(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.spcambo.test']);
        $user = User::factory()->create(['email' => 'recover+customer@example.test']);
        $expected = ['data' => ['message' => 'If that account exists, password reset instructions have been sent.']];

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson($expected);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])
            ->assertOk()
            ->assertExactJson($expected);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith('https://app.spcambo.test/reset-password/', $url);
            $this->assertStringContainsString('email=recover%2Bcustomer%40example.test', $url);
            $this->assertStringNotContainsString(config('app.url'), $url);

            return true;
        });
        Notification::assertCount(1);
    }

    public function test_unauthenticated_identity_request_is_rejected(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Authentication is required.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_suspended_user_cannot_log_in_or_use_an_existing_token(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('a-secure-test-password'),
            'status' => AccountStatus::Suspended,
        ]);
        $token = $user->createToken('browser')->plainTextToken;

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-secure-test-password',
        ])->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');
    }
}
