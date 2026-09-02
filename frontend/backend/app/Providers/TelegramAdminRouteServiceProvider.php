<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TelegramAdminRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware([
            'api',
            'auth:sanctum',
            'account.active',
            'permission:catalog.manage',
        ])
            ->prefix('api/v1/admin/telegram-store')
            ->group(base_path('routes/admin_telegram.php'));
    }
}
