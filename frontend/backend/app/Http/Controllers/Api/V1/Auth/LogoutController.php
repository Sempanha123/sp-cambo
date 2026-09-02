<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class LogoutController extends Controller
{
    /**
     * Revoke the token used for the current request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            $accessToken = Sanctum::personalAccessTokenModel()::findToken($token);

            if ($accessToken !== null && $accessToken->tokenable_id === $request->user()->getKey()) {
                // Revoke Google OAuth token if linked
                $this->revokeGoogleToken($request->user());

                $accessToken->delete();
            }
        } else {
            // Revoke Google OAuth token if linked
            $this->revokeGoogleToken($request->user());

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'data' => [
                'message' => 'Logged out.',
            ],
        ]);
    }

    /**
     * Revoke the Google OAuth token for the user.
     */
    protected function revokeGoogleToken($user): void
    {
        $googleIdentity = ExternalIdentity::where('user_id', $user->getKey())
            ->where('provider', 'google')
            ->first();

        if ($googleIdentity && $googleIdentity->token) {
            try {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                    'token' => $googleIdentity->token,
                ]);
            } catch (\Throwable) {
                // Ignore revocation failures; token may already be expired/invalid
            }
        }
    }
}
