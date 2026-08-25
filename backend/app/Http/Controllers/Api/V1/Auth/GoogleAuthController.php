<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SafeUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    private const STATE_TTL_SECONDS = 600;

    /**
     * Build a Google authorization URL without depending on a cross-origin
     * Laravel session cookie. SP Cambo runs the browser in bearer-token mode,
     * so the OAuth state is a short-lived encrypted payload instead.
     */
    public function redirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent' => ['nullable', Rule::in(['login', 'link'])],
            'domain' => ['nullable', 'string', 'max:253'],
        ]);

        $intent = $data['intent'] ?? 'login';
        $domain = isset($data['domain']) ? trim((string) $data['domain']) : null;

        if ($intent === 'link' && ! $request->user('sanctum')) {
            return response()->json([
                'message' => 'Authentication is required before linking a Google account.',
                'code' => 'unauthenticated',
            ], 401);
        }

        $state = $this->makeState($intent, $domain);

        $parameters = [
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'offline',
            'redirect_uri' => config('services.google.redirect'),
        ];

        if ($domain) {
            $parameters['hd'] = $domain;
        }

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->with($parameters)
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'data' => [
                'url' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Exchange the Google authorization code for a normal SP Cambo bearer
     * session. Google redirects to the Nuxt callback page, and Nuxt posts the
     * code + encrypted state here so no SP Cambo token ever travels in a URL.
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $state = $this->readState($data['state'], 'login');
        $socialiteUser = $this->googleUser($request, $data['code']);
        $this->assertHostedDomain($socialiteUser, $state['domain'] ?? null);

        $user = $this->findOrCreateUser($socialiteUser);

        if ($user->status !== AccountStatus::Active) {
            return response()->json([
                'message' => 'This account is not active.',
                'code' => 'account_suspended',
            ], 403);
        }

        $token = $user->createToken('google-login')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => SafeUserData::from($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Exchange a Google authorization code and link that identity to the
     * currently authenticated SP Cambo account.
     */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $state = $this->readState($data['state'], 'link');
        $socialiteUser = $this->googleUser($request, $data['code']);
        $this->assertHostedDomain($socialiteUser, $state['domain'] ?? null);

        $identity = $this->linkIdentity($request->user(), $socialiteUser);

        return response()->json([
            'data' => [
                'success' => true,
                'message' => 'Google account linked successfully.',
                'identity_id' => (string) $identity->id,
            ],
        ]);
    }

    protected function findOrCreateUser(SocialiteUser $socialiteUser): User
    {
        return DB::transaction(function () use ($socialiteUser): User {
            $identity = ExternalIdentity::query()
                ->where('provider', 'google')
                ->where('provider_subject', $socialiteUser->getId())
                ->first();

            if ($identity) {
                return $identity->user;
            }

            $email = $socialiteUser->getEmail();
            if (! $email) {
                throw ValidationException::withMessages([
                    'google' => ['Google did not provide an email address for this account.'],
                ]);
            }

            $email = Str::lower(trim($email));
            $existingUser = User::query()->where('email', $email)->first();

            if ($existingUser) {
                if (! $this->googleEmailIsVerified($socialiteUser)) {
                    throw ValidationException::withMessages([
                        'google' => ['Google did not confirm this email address, so it cannot be linked automatically.'],
                    ]);
                }

                $this->linkIdentity($existingUser, $socialiteUser);

                return $existingUser;
            }

            // The users table intentionally keeps a non-null password column.
            // Google-only users receive an unguessable local password and may set
            // their own later through the normal password-reset flow.
            $name = $socialiteUser->getName() ?: Str::before($email, '@');
            $tenant = Tenant::query()->create(['name' => $name.' workspace']);
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Str::random(64),
                'status' => AccountStatus::Active,
                'tenant_id' => $tenant->id,
            ]);

            $customerRole = Role::query()->firstOrCreate(
                ['name' => 'CUSTOMER'],
                ['label' => 'Customer'],
            );
            $user->roles()->syncWithoutDetaching([$customerRole->id]);

            $this->createIdentity($user, $socialiteUser);

            CustomerStateChanged::dispatch($user->id, 'user.created', [
                'source' => 'google',
                'provider_subject' => $socialiteUser->getId(),
            ]);

            return $user;
        });
    }

    protected function linkIdentity(User $user, SocialiteUser $socialiteUser): ExternalIdentity
    {
        return DB::transaction(function () use ($user, $socialiteUser): ExternalIdentity {
            $existingIdentity = ExternalIdentity::query()
                ->where('provider', 'google')
                ->where('provider_subject', $socialiteUser->getId())
                ->first();

            if ($existingIdentity) {
                if ((int) $existingIdentity->user_id === (int) $user->id) {
                    return $existingIdentity;
                }

                throw ValidationException::withMessages([
                    'google' => ['This Google account is already linked to another SP Cambo account.'],
                ]);
            }

            $existingGoogleIdentity = ExternalIdentity::query()
                ->where('user_id', $user->id)
                ->where('provider', 'google')
                ->first();

            if ($existingGoogleIdentity) {
                throw ValidationException::withMessages([
                    'google' => ['This SP Cambo account already has a Google account linked.'],
                ]);
            }

            $identity = $this->createIdentity($user, $socialiteUser);

            CustomerStateChanged::dispatch($user->id, 'external_identity.linked', [
                'provider' => 'google',
                'provider_subject' => $socialiteUser->getId(),
            ]);

            return $identity;
        });
    }

    private function createIdentity(User $user, SocialiteUser $socialiteUser): ExternalIdentity
    {
        // OAuth access/refresh tokens are not needed for sign-in after the
        // identity has been established, so do not persist provider secrets.
        return ExternalIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => $socialiteUser->getId(),
            'email' => $socialiteUser->getEmail(),
            'email_verified_at' => $this->googleEmailIsVerified($socialiteUser) ? now() : null,
            'name' => $socialiteUser->getName(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ]);
    }

    private function googleUser(Request $request, string $code): SocialiteUser
    {
        // Socialite reads the authorization code from the current request. The
        // frontend posts it in JSON; mirror it into the query bag as well for
        // provider/version compatibility.
        $request->query->set('code', $code);

        return Socialite::driver('google')
            ->stateless()
            ->user();
    }

    /** @return array{intent:string,domain:?string,issued_at:int,nonce:string} */
    private function readState(string $encryptedState, string $expectedIntent): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($encryptedState), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'state' => ['The Google sign-in request is invalid or has expired. Please try again.'],
            ]);
        }

        if (! is_array($decoded)
            || ($decoded['intent'] ?? null) !== $expectedIntent
            || ! is_int($decoded['issued_at'] ?? null)
            || ! is_string($decoded['nonce'] ?? null)
            || (time() - $decoded['issued_at']) > self::STATE_TTL_SECONDS
            || $decoded['issued_at'] > (time() + 30)) {
            throw ValidationException::withMessages([
                'state' => ['The Google sign-in request is invalid or has expired. Please try again.'],
            ]);
        }

        return [
            'intent' => $decoded['intent'],
            'domain' => isset($decoded['domain']) && is_string($decoded['domain']) && $decoded['domain'] !== '' ? $decoded['domain'] : null,
            'issued_at' => $decoded['issued_at'],
            'nonce' => $decoded['nonce'],
        ];
    }

    private function makeState(string $intent, ?string $domain): string
    {
        return Crypt::encryptString(json_encode([
            'intent' => $intent,
            'domain' => $domain,
            'issued_at' => time(),
            'nonce' => Str::random(24),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertHostedDomain(SocialiteUser $socialiteUser, ?string $domain): void
    {
        if (! $domain) {
            return;
        }

        $googleDomain = $socialiteUser->user['hd'] ?? null;
        if (! is_string($googleDomain) || ! hash_equals(Str::lower($domain), Str::lower($googleDomain))) {
            throw ValidationException::withMessages([
                'google' => ["Only {$domain} Google accounts are allowed."],
            ]);
        }
    }

    private function googleEmailIsVerified(SocialiteUser $socialiteUser): bool
    {
        $verified = $socialiteUser->user['email_verified'] ?? $socialiteUser->user['verified_email'] ?? false;

        return filter_var($verified, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $verified;
    }
}
