<?php

namespace App\Providers;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Services\Payments\BakongOpenApiClient;
use App\Services\Payments\HttpKhqrGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return $frontendUrl.'/reset-password/'.$token.'?'.http_build_query([
                'email' => $notifiable->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);
        });
    }
}
