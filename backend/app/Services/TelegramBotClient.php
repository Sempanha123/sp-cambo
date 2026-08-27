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

    /** @param array<string,mixed>|null $replyMarkup */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $response = $this->request()->post($this->endpoint('sendMessage'), $payload);
        if (! $response->successful() || $response->json('ok') !== true) {
            $description = trim((string) $response->json('description', ''));
            $suffix = $description !== '' ? ': '.mb_substr($description, 0, 240) : '';
            throw new RuntimeException('Telegram sendMessage was rejected (HTTP '.$response->status().')'.$suffix);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && trim($text) !== '') {
            $payload['text'] = $text;
        }

        $response = $this->request()->post($this->endpoint('answerCallbackQuery'), $payload);
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram callback acknowledgement failed.');
        }
    }

    private function endpoint(string $method): string
    {
        $token = trim((string) config('services.telegram.bot_token'));
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        return "https://api.telegram.org/bot{$token}/{$method}";
    }
}
