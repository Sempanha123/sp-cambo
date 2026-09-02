<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    private function request(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.telegram.timeout_seconds', 15));
    }

    /** @param array<string,mixed>|null $replyMarkup @return array<string,mixed> */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $result = $this->call('sendMessage', $payload);

        return is_array($result) ? $result : [];
    }

    /** @param array<string,mixed>|null $replyMarkup @return array<string,mixed> */
    public function sendPhotoBytes(
        string $chatId,
        string $pngBytes,
        string $caption = '',
        ?array $replyMarkup = null,
    ): array {
        if (strlen($pngBytes) < 16 || ! str_starts_with($pngBytes, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Telegram photo payload is not a valid PNG.');
        }

        $payload = ['chat_id' => $chatId];
        if ($caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        $response = Http::acceptJson()
            ->timeout((int) config('services.telegram.timeout_seconds', 15))
            ->attach('photo', $pngBytes, 'sp-cambo-khqr.png')
            ->post($this->endpoint('sendPhoto'), $payload);

        $json = $response->json();
        $this->assertSuccessful('sendPhoto', $response->status(), $json);

        return is_array($json) && is_array($json['result'] ?? null)
            ? $json['result']
            : [];
    }

    /** @param array<string,mixed>|null $replyMarkup @return array<string,mixed> */
    public function editMessageCaption(
        string $chatId,
        int $messageId,
        string $caption,
        ?array $replyMarkup = null,
    ): array {
        if ($messageId <= 0) {
            return [];
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $result = $this->call('editMessageCaption', $payload);
            return is_array($result) ? $result : [];
        } catch (RuntimeException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'message is not modified')) {
                return [];
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed>|null $replyMarkup @return array<string,mixed> */
    public function editMessageText(
        string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
    ): array {
        if ($messageId <= 0) {
            return [];
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $result = $this->call('editMessageText', $payload);
            return is_array($result) ? $result : [];
        } catch (RuntimeException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'message is not modified')) {
                return [];
            }
            throw $exception;
        }
    }

    public function deleteMessage(string $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && trim($text) !== '') {
            $payload['text'] = $text;
        }

        $this->call('answerCallbackQuery', $payload);
    }

    /** @return array<string,mixed> */
    public function getMe(): array
    {
        $result = $this->call('getMe');

        if (! is_array($result)) {
            throw new RuntimeException('Telegram getMe returned an invalid response.');
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function getWebhookInfo(): array
    {
        $result = $this->call('getWebhookInfo');

        if (! is_array($result)) {
            throw new RuntimeException('Telegram getWebhookInfo returned an invalid response.');
        }

        return $result;
    }

    /** @param array<int,string> $allowedUpdates */
    public function setWebhook(
        string $url,
        string $secretToken,
        array $allowedUpdates = ['message', 'callback_query'],
        bool $dropPendingUpdates = false,
    ): void {
        if (! filter_var($url, FILTER_VALIDATE_URL) || mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Telegram webhook URL must be a valid HTTPS URL.');
        }

        if ($secretToken === '' || preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secretToken) !== 1) {
            throw new RuntimeException('Telegram webhook secret must use 1-256 letters, numbers, underscores or hyphens.');
        }

        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => array_values($allowedUpdates),
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    /** @param array<int,array{command:string,description:string}> $commands */
    public function setMyCommands(array $commands): void
    {
        $this->call('setMyCommands', ['commands' => array_values($commands)]);
    }

    /** @param array<string,mixed> $payload */
    private function call(string $method, array $payload = []): mixed
    {
        $response = $this->request()->post($this->endpoint($method), $payload);
        $json = $response->json();
        $this->assertSuccessful($method, $response->status(), $json);

        return is_array($json) ? ($json['result'] ?? null) : null;
    }

    private function assertSuccessful(string $method, int $status, mixed $json): void
    {
        $ok = is_array($json) && ($json['ok'] ?? false) === true;
        if ($status >= 200 && $status < 300 && $ok) {
            return;
        }

        $description = is_array($json) ? trim((string) ($json['description'] ?? '')) : '';
        $suffix = $description !== '' ? ': '.mb_substr($description, 0, 240) : '';

        throw new RuntimeException('Telegram '.$method.' was rejected (HTTP '.$status.')'.$suffix);
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
