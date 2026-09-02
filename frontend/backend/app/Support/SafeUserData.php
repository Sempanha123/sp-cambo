<?php

namespace App\Support;

use App\Models\User;

class SafeUserData
{
    /**
     * Return account identity and authorization metadata safe for the authenticated user.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     email_verified_at: ?string,
     *     created_at: string,
     *     roles: list<string>,
     *     permissions: list<string>
     * }
     */
    public static function from(User $user): array
    {
        $user->loadMissing('roles.permissions');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toAtomString(),
            'created_at' => $user->created_at->toAtomString(),
            'roles' => $user->roles
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'permissions' => $user->roles
                ->flatMap(fn ($role) => $role->permissions->pluck('name'))
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
