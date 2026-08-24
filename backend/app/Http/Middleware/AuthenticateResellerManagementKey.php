<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\ResellerManagementKey;
use App\Services\ResellerManagementKeyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateResellerManagementKey
{
    public function __construct(private readonly ResellerManagementKeyService $secrets) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) $request->bearerToken();
        if (! str_starts_with($secret, ResellerManagementKeyService::PREFIX)) {
            return new JsonResponse(['message' => 'Management authentication failed.', 'code' => 'invalid_management_key'], 401);
        }
        $key = ResellerManagementKey::query()->with('user')->where('lookup_digest', $this->secrets->digest($secret))->first();
        if (! $key || $key->status !== 'ACTIVE' || $key->expires_at?->isPast() || $key->user->status !== AccountStatus::Active || ! $key->user->hasPermission('reseller.manage')) {
            return new JsonResponse(['message' => 'Management key is not active.', 'code' => 'invalid_management_key'], 401);
        }
        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('management_key', $key);
        $request->setUserResolver(fn () => $key->user);

        return $next($request);
    }
}
