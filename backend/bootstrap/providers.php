<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelegramAdminRouteServiceProvider;
use App\Providers\TelegramOrderRetentionServiceProvider;

return [
    AppServiceProvider::class,
    TelegramOrderRetentionServiceProvider::class,
    TelegramAdminRouteServiceProvider::class,
];
