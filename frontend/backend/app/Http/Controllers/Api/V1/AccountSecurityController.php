<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use App\Services\AuditService;
use App\Support\SafeUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountSecurityController extends Controller
{
    public function updateProfile(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->update(['name' => trim($data['name'])]);
        $audit->record($request->user(), 'account.profile.updated', 'user', $request->user()->id, 'Customer updated account profile.');

        return response()->json(['data' => ['user' => $this->user($request)]]);
    }

    public function updatePassword(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $request->user()->update(['password' => $data['password']]);
        $currentId = $request->user()->currentAccessToken()?->id;
        $request->user()->tokens()->when($currentId, fn ($query) => $query->whereKeyNot($currentId))->delete();
        $audit->record($request->user(), 'account.password.updated', 'user', $request->user()->id, 'Customer changed password and revoked other bearer sessions.');

        return response()->json(['data' => ['message' => 'Password updated. Other sessions were revoked.']]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json(['data' => $request->user()->tokens()->latest()->get()->map(fn ($token) => [
            'id' => (string) $token->id,
            'name' => $token->name,
            'current' => (int) $token->id === (int) $currentId,
            'last_used_at' => $token->last_used_at?->toAtomString(),
            'created_at' => $token->created_at->toAtomString(),
        ])]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($session);
        $token->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * List external identities linked to the authenticated user.
     */
    public function identities(Request $request): JsonResponse
    {
        $identities = $request->user()->externalIdentities()->latest()->get()->map(function (ExternalIdentity $identity) {
            return [
                'id' => (string) $identity->id,
                'provider' => $identity->provider,
                'provider_subject' => $identity->provider_subject,
                'email' => $identity->email,
                'name' => $identity->name,
                'avatar_url' => $identity->avatar_url,
                'created_at' => $identity->created_at->toAtomString(),
            ];
        });

        return response()->json(['data' => $identities]);
    }

    /**
     * Unlink an external identity from the authenticated user.
     */
    public function unlinkIdentity(Request $request, string $identityId, AuditService $audit): JsonResponse
    {
        $identity = $request->user()->externalIdentities()->findOrFail($identityId);
        $provider = $identity->provider;
        $email = $identity->email ?? $identity->provider_subject;

        $identity->delete();

        $audit->record($request->user(), 'account.identity.unlinked', 'user', $request->user()->id, "Customer unlinked {$provider} account ({$email}).");

        return response()->json(['data' => ['success' => true]]);
    }

    private function user(Request $request): array
    {
        $user = $request->user();

        return SafeUserData::from($user);
    }
}
