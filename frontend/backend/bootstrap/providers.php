<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModelRoutePoolServiceProvider;
use App\Providers\TelegramAdminRouteServiceProvider;
use App\Providers\TelegramOrderRetentionServiceProvider;

return [
    AppServiceProvider::class,
    ModelRoutePoolServiceProvider::class,
    TelegramOrderRetentionServiceProvider::class,
    TelegramAdminRouteServiceProvider::class,
];
