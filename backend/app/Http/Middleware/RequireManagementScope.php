<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireManagementScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->attributes->get('management_key');
        if (! $key || ! in_array($scope, $key->scopes, true)) {
            return new JsonResponse(['message' => 'Management key scope is insufficient.', 'code' => 'insufficient_scope'], 403);
        }

        return $next($request);
    }
}
