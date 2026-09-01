<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModelRoutePoolRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware([
            'api',
            'auth:sanctum',
            'account.active',
            'permission:catalog.manage',
        ])
            ->prefix('api/v1/admin/model-route-pools')
            ->group(base_path('routes/admin_model_route_pools.php'));
    }
}
