<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SafeUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /**
     * Create a local user and issue a Sanctum API token.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $name = $request->string('name')->trim()->value();
            $tenant = Tenant::query()->create(['name' => $name.' workspace']);
            $user = User::query()->create([
                'name' => $name,
                'email' => $request->string('email')->lower()->value(),
                'password' => Hash::make($request->string('password')->value()),
            ]);
            $user->forceFill(['tenant_id' => $tenant->id])->save();
            // Registration must remain available on a fresh migrated database.
            // Seeders still establish the complete authorization baseline, but a
            // missing CUSTOMER row should never turn a public sign-up into a 500.
            $customerRole = Role::query()->firstOrCreate(
                ['name' => 'CUSTOMER'],
                ['label' => 'Customer'],
            );
            $user->roles()->syncWithoutDetaching([$customerRole->id]);

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
