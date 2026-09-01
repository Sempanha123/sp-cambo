<?php

namespace App\Providers;

use App\Http\Controllers\Api\V1\Admin\ModelRoutePoolController;
use App\Http\Controllers\Api\V1\Internal\GatewayRouteController;
use App\Services\RoutePoolSystemHealthService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModelRoutePoolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SystemHealthService::class,
            RoutePoolSystemHealthService::class,
        );
    }

    public function boot(): void
    {
        Route::middleware([
            'api',
            'auth:sanctum',
            'account.active',
            'permission:catalog.manage',
        ])
            ->prefix('api/v1/admin/model-route-pools')
            ->group(function (): void {
                Route::get('/', [ModelRoutePoolController::class, 'index']);
                Route::get('{modelAlias}', [ModelRoutePoolController::class, 'show']);
                Route::put('{modelAlias}', [ModelRoutePoolController::class, 'update'])
                    ->middleware('throttle:20,1');
                Route::post('{modelAlias}/revisions/{revision}/reset-circuit', [ModelRoutePoolController::class, 'resetCircuit'])
                    ->middleware('throttle:20,1');
            });

        Route::middleware([
            'api',
            'gateway.auth',
            'throttle:600,1',
        ])
            ->prefix('api/v1/internal/gateway')
            ->group(function (): void {
                Route::post('reservations/{reservation}/reroute', [GatewayRouteController::class, 'reroute']);
                Route::post('reservations/{reservation}/route-success', [GatewayRouteController::class, 'success']);
            });
    }
}
