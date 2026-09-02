<?php

use App\Http\Controllers\Api\V1\Admin\TelegramStoreController;
use Illuminate\Support\Facades\Route;

Route::put('settings', [TelegramStoreController::class, 'updateNotificationSettings'])->middleware('throttle:20,1');

Route::post('channels', [TelegramStoreController::class, 'storeChannel'])->middleware('throttle:20,1');
Route::put('channels/{channel}', [TelegramStoreController::class, 'updateChannel'])->middleware('throttle:20,1');
Route::delete('channels/{channel}', [TelegramStoreController::class, 'destroyChannel'])->middleware('throttle:20,1');
Route::post('channels/{channel}/test', [TelegramStoreController::class, 'testChannel'])->middleware('throttle:10,1');
