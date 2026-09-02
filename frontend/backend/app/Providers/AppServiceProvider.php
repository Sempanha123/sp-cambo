<?php

namespace App\Providers;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Services\Payments\BakongOpenApiClient;
use App\Services\Payments\HttpKhqrGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BakongVerifier::class, BakongOpenApiClient::class);
        $this->app->bind(KhqrGenerator::class, HttpKhqrGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep hosted Playground traffic isolated from unrelated throttled routes.
        // Generic numeric throttle middleware keys can share a user/IP counter, so
        // quota/history refreshes must never make a normal chat message return 429.
        RateLimiter::for('playground-inference', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(60)
                ->by('playground-inference:'.$identity)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'The Playground is receiving messages too quickly. Please wait a few seconds and try again.',
                    'code' => 'playground_rate_limited',
                ], 429, $headers));
        });

        RateLimiter::for('playground-read', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(240)->by('playground-read:'.$identity);
        });

        RateLimiter::for('playground-history-write', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by('playground-history-write:'.$identity);
        });

        RateLimiter::for('playground-history-destructive', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(30)->by('playground-history-destructive:'.$identity);
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return $frontendUrl.'/reset-password/'.$token.'?'.http_build_query([
                'email' => $notifiable->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);
        });
    }
}
