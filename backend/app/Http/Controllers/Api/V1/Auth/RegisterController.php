<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SafeUserData;
use App\Services\ReferralService;
use App\Services\Auth\RegistrationEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /**
     * Create a local user and issue a Sanctum API token.
     */
    public function __invoke(
        RegisterRequest $request,
        ReferralService $referrals,
        RegistrationEmailVerificationService $verification,
    ): JsonResponse {
        $name = $request->string('name')->trim()->value();
        $email = $request->string('email')->lower()->value();

        // Verification is committed before account creation so incorrect-attempt
        // counters cannot be rolled back with the registration transaction.
        $verification->verifyOrFail($email, $request->string('verification_code')->value());

        $user = DB::transaction(function () use ($request, $referrals, $verification, $name, $email): User {
            // Consumption stays in the same transaction as user creation: if
            // creation fails, the verified code remains retryable; if it succeeds,
            // concurrent reuse is impossible.
            $verification->consumeVerifiedOrFail($email);
            $tenant = Tenant::query()->create(['name' => $name.' workspace']);
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($request->string('password')->value()),
                'tenant_id' => $tenant->id,
            ]);
            // Registration must remain available on a fresh migrated database.
            // Seeders still establish the complete authorization baseline, but a
            // missing CUSTOMER row should never turn a public sign-up into a 500.
            $customerRole = Role::query()->firstOrCreate(
                ['name' => 'CUSTOMER'],
                ['label' => 'Customer'],
            );
            $user->roles()->syncWithoutDetaching([$customerRole->id]);

            $referralCode = strtoupper($request->string('referral_code')->trim()->value());
            if ($referralCode !== '' && $referrals->settings()->enabled && User::query()->where('referral_code', $referralCode)->exists()) {
                $user = $referrals->claim($user, $referralCode);
            }

            return $user;
        });

        $token = null;
        if ($request->attributes->get('sanctum') === true) {
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
        ], 201);
    }
}
