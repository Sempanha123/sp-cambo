<?php

namespace App\Providers;

use App\Services\TelegramPendingOrderPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class TelegramOrderRetentionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        // The application already runs Laravel's scheduler every minute. Register
        // stale Telegram cleanup here so abandoned unpaid orders disappear after
        // one hour without requiring another cron entry.
        $schedule->call(function (): void {
            app(TelegramPendingOrderPolicy::class)->cleanupExpired(null, 100);
        })
            ->name('telegram:cleanup-expired-unpaid-orders')
            ->everyMinute()
            ->withoutOverlapping();
    }
}
