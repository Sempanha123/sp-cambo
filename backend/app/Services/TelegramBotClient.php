<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    private function request(): PendingRequest
    {
        return Http::asJson()->acceptJson()->timeout((int) config('services.telegram.timeout_seconds', 15));
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $token = trim((string) config('services.telegram.bot_token'));
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = $this->request()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram delivery failed.');
        }
    }
}
