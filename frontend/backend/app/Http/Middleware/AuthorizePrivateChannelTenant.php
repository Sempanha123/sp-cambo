<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePrivateChannelTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $channel = (string) $request->input('channel_name');
        if (preg_match('/^private-users\.(\d+)$/D', $channel, $matches) !== 1
            || (int) $matches[1] !== (int) $request->user()?->id) {
            abort(403);
        }

        return $next($request);
    }
}
