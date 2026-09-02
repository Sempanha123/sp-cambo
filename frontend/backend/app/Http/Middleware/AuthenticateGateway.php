<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.spcambo.gateway_secret');
        $provided = (string) $request->bearerToken();

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            return new JsonResponse(['message' => 'Gateway authentication failed.', 'code' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
