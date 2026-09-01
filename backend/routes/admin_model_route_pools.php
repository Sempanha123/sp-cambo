<?php

use App\Http\Controllers\Api\V1\Admin\ModelRoutePoolController;
use Illuminate\Support\Facades\Route;

Route::get('{modelAlias}', [ModelRoutePoolController::class, 'show']);
Route::put('{modelAlias}', [ModelRoutePoolController::class, 'update'])->middleware('throttle:20,1');
