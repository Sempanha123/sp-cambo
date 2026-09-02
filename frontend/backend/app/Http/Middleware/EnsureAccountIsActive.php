<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== AccountStatus::Active) {
            return new JsonResponse([
                'message' => 'This account is not active.',
                'code' => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
