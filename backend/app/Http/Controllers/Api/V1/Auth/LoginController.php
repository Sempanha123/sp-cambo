<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use App\Support\SafeUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class LoginController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Verify credentials and create the browser session requested by the frontend.
     *
     * SP Cambo supports two browser transports:
     * - cookie mode: Nuxt first obtains /sanctum/csrf-cookie and therefore sends
     *   X-XSRF-TOKEN. For a Sanctum-stateful request we establish the web session.
     * - bearer mode: no X-XSRF-TOKEN header is sent, so we issue a Sanctum token.
     *
     * Detecting the transport from the actual CSRF/stateful request fixes the old
     * `$request->attributes->get('sanctum')` check, which was not a reliable signal.
     *
     * @throws ValidationException
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->lower()->value();
        $throttleKey = $this->throttleKey($email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_ATTEMPTS)) {
            $retryAfter = max(1, RateLimiter::availableIn($throttleKey));

            return response()->json([
                'message' => "Too many failed sign-in attempts. Try again in {$retryAfter} seconds.",
                'code' => 'rate_limit_exceeded',
                'retry_after_seconds' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // A valid credential should never be penalized by previous failed tries.
        RateLimiter::clear($throttleKey);

        if ($user->status !== AccountStatus::Active) {
            return response()->json([
                'message' => 'This account is not active.',
                'code' => 'account_suspended',
            ], 403);
        }

        $token = null;

        if ($this->usesCookieSession($request)) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        } else {
            $token = $user->createToken('browser')->plainTextToken;
        }

        return response()->json([
            'data' => [
                'user' => SafeUserData::from($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Cookie mode in the Nuxt client fetches a Sanctum CSRF cookie before POSTing
     * login/register, so X-XSRF-TOKEN is present only for that transport.
     *
     * Requiring Sanctum's stateful-origin check prevents an arbitrary third-party
     * origin from turning a login request into a first-party browser session.
     */
    private function usesCookieSession(LoginRequest $request): bool
    {
        return $request->hasHeader('X-XSRF-TOKEN')
            && $request->hasSession()
            && EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    private function throttleKey(string $email, string $ip): string
    {
        return 'login|'.Str::lower(trim($email)).'|'.$ip;
    }
}
