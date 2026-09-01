<?php

namespace App\Jobs;

use App\Services\TelegramQrCountdownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshTelegramQrCountdown implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [5, 15];

    public int $timeout = 30;

    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {}

    public function handle(TelegramQrCountdownService $countdown): void
    {
        $next = $countdown->refresh($this->subjectType, $this->subjectId);

        if ($next !== null) {
            self::dispatch($this->subjectType, $this->subjectId)
                ->delay(now()->addSeconds($next));
        }
    }
}
