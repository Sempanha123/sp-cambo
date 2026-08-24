<?php

namespace App\Services;

use App\Models\ResellerManagementKey;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class ResellerManagementKeyService
{
    public const PREFIX = 'sk-spm-';

    public function create(User $user, string $label, array $scopes, $expiresAt = null): array
    {
        $secret = self::PREFIX.Str::lower(Str::random(48));
        $key = ResellerManagementKey::query()->create(['user_id' => $user->id, 'label' => $label, 'prefix' => self::PREFIX, 'last_four' => substr($secret, -4), 'lookup_digest' => $this->digest($secret), 'scopes' => array_values(array_unique($scopes)), 'status' => 'ACTIVE', 'expires_at' => $expiresAt]);

        return ['key' => $key, 'secret' => $secret];
    }

    public function digest(string $secret): string
    {
        $lookupSecret = (string) config('services.spcambo.management_key_lookup_secret');
        if ($lookupSecret === '') {
            throw new RuntimeException('Management key lookup secret is not configured.');
        }

        return hash_hmac('sha256', $secret, $lookupSecret);
    }
}
