<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ExternalIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google for authentication
     */
    public function redirect(Request $request)
    {
        $intent = $request->input('intent', 'login');
        $domain = $request->input('domain');

        $redirectUrl = route('auth.google.callback');

        $driver = Socialite::driver('google')
            ->stateless()
            ->with([
                'redirect_uri' => $redirectUrl,
                'prompt' => 'select_account',
            ]);

        if ($domain) {
            $driver->with(['hd' => $domain]);
        }

        // Store intent in session
        session([
            'google_auth_intent' => $intent,
            'google_auth_domain' => $domain
        ]);

        return $driver->redirect();
    }

    /**
     * Handle Google callback
     */
    public function callback(Request $request)
    {
        try {
            $intent = session('google_auth_intent', 'login');
            $domain = session('google_auth_domain');

            // Clear session
            session()->forget(['google_auth_intent', 'google_auth_domain']);

            $googleUser = Socialite::driver('google')->stateless()->user();

            // Check domain if specified
            if ($domain && $googleUser->user['hd'] !== $domain) {
                throw new BadRequestHttpException("Only {$domain} accounts are allowed.");
            }

            // Find or create user based on intent
            if ($intent === 'link') {
                return $this->linkAccount($googleUser);
            } else {
                return $this->loginOrRegister($googleUser);
            }
        } catch (\Exception $e) {
            Log::error('Google OAuth error: ' . $e->getMessage());
            throw new BadRequestHttpException('Google authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Login or register user
     */
    protected function loginOrRegister($googleUser)
    {
        $identity = ExternalIdentity::where('provider', 'google')
            ->where('provider_subject', $googleUser->getId())
            ->first();

        if ($identity) {
            $user = $identity->user;
        } else {
            // Create new user
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'email_verified_at' => now(),
            ]);

            // Create identity
            $user->identities()->create([
                'provider' => 'google',
                'provider_subject' => $googleUser->getId(),
                'email' => $googleUser->getEmail(),
                'name' => $googleUser->getName(),
                'avatar_url' => $googleUser->getAvatar(),
            ]);
        }

        // Login the user
        Auth::login($user, true);

        // Create token for API access
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * Link Google account to existing user
     */
    protected function linkAccount($googleUser)
    {
        $user = Auth::user();

        if (!$user) {
            throw new BadRequestHttpException('You must be logged in to link accounts.');
        }

        // Check if this Google account is already linked
        $existingIdentity = ExternalIdentity::where('provider', 'google')
            ->where('provider_subject', $googleUser->getId())
            ->first();

        if ($existingIdentity) {
            if ($existingIdentity->user_id === $user->id) {
                return response()->json(['success' => true, 'message' => 'Account already linked']);
            } else {
                throw new BadRequestHttpException('This Google account is already linked to another user.');
            }
        }

        // Create new identity
        $user->identities()->create([
            'provider' => 'google',
            'provider_subject' => $googleUser->getId(),
            'email' => $googleUser->getEmail(),
            'name' => $googleUser->getName(),
            'avatar_url' => $googleUser->getAvatar(),
        ]);

        return response()->json(['success' => true]);
    }
}