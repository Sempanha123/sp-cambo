<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class ApiKeySecretService
{
    public const PREFIX = 'sk-spc-';

    /** @return array{key: ApiKey, secret: string} */
    public function create(User $user, array $attributes, array $modelAliasIds): array
    {
        $secret = self::PREFIX.Str::lower(Str::random(48));
        $tenant = $user->requireTenant();
        $key = new ApiKey($attributes + [
            'tenant_id' => $tenant->id,
            'prefix' => self::PREFIX,
            'last_four' => substr($secret, -4),
            'lookup_digest' => $this->digest($secret),
            'status' => 'ACTIVE',
        ]);
        $key->user()->associate($user);
        $key->save();
        $key->modelAliases()->sync($modelAliasIds);

        return ['key' => $key->load('modelAliases'), 'secret' => $secret];
    }

    public function rotate(ApiKey $key): string
    {
        $secret = self::PREFIX.Str::lower(Str::random(48));
        $key->forceFill(['lookup_digest' => $this->digest($secret), 'last_four' => substr($secret, -4), 'status' => 'ACTIVE'])->save();

        return $secret;
    }

    public function digest(string $secret): string
    {
        $lookupSecret = (string) (config('services.spcambo.api_key_lookup_secret') ?: config('app.key'));
        if ($lookupSecret === '') {
            throw new RuntimeException('API key lookup secret is not configured.');
        }

        return hash_hmac('sha256', $secret, $lookupSecret);
    }
}
