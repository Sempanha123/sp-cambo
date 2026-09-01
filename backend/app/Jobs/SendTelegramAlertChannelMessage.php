<?php

namespace App\Jobs;

use App\Models\TelegramAlertChannel;
use App\Services\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramAlertChannelMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [15, 60, 180, 600];

    public int $timeout = 30;

    public function __construct(
        public readonly int $channelId,
        public readonly string $text,
    ) {}

    public function handle(TelegramBotClient $bot): void
    {
        $channel = TelegramAlertChannel::query()->find($this->channelId);

        if (! $channel || ! $channel->enabled) {
            return;
        }

        $bot->sendMessage((string) $channel->chat_id, mb_substr($this->text, 0, 4000));
    }
}
