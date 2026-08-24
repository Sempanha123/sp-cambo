<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramCommerceService $telegram, TelegramBotClient $bot): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $actual = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($expected === '' || ! hash_equals($expected, $actual)) abort(403);

        $updateId = (string) $request->input('update_id', 'unknown');
        $message = $request->input('message');
        if (! is_array($message)) return response()->json(['ok' => true]);
        $chatId = (string) data_get($message, 'chat.id', '');
        $chatType = (string) data_get($message, 'chat.type', '');
        $telegramUserId = (string) data_get($message, 'from.id', '');
        $username = data_get($message, 'from.username');
        $text = trim((string) data_get($message, 'text', ''));
        if ($chatId === '' || $telegramUserId === '' || $text === '') return response()->json(['ok' => true]);
        if ($chatType !== 'private') {
            try { $bot->sendMessage($chatId, 'For security, SP Cambo account linking and purchases are available only in a private chat with the bot.'); } catch (Throwable) {}
            return response()->json(['ok' => true]);
        }

        try {
            [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');
            $command = mb_strtolower(preg_replace('/@[^\s]+$/', '', $command) ?? $command);

            if ($command === '/start') {
                $bot->sendMessage($chatId, "SP Cambo bot\nLink your website account first, then use /plans, /buy PLAN_SLUG, and /check.");
                return response()->json(['ok' => true]);
            }
            if ($command === '/link') {
                $telegram->link($argument, $telegramUserId, $chatId, is_string($username) ? $username : null);
                $bot->sendMessage($chatId, "Your Telegram account is linked to SP Cambo. Use /plans to view available plans.");
                return response()->json(['ok' => true]);
            }

            $account = $telegram->accountForChat($chatId);
            if (! $account) {
                $bot->sendMessage($chatId, "Link this Telegram chat from your SP Cambo dashboard before purchasing.");
                return response()->json(['ok' => true]);
            }
            if ($command === '/plans') $bot->sendMessage($chatId, $telegram->planText());
            elseif ($command === '/buy' && trim($argument) !== '') $telegram->beginPurchase($account, trim($argument), $updateId);
            elseif ($command === '/check') {
                $purchase = $telegram->checkLatest($account);
                if (! $purchase) $bot->sendMessage($chatId, "No Telegram purchase was found.");
                elseif ($purchase->delivered_at === null) $bot->sendMessage($chatId, "Payment has not been verified yet. Try /check again shortly.");
            } else $bot->sendMessage($chatId, "Commands: /plans, /buy PLAN_SLUG, /check");
        } catch (Throwable $e) {
            report($e);
            try { $bot->sendMessage($chatId, "SP Cambo could not complete that command. Please retry or use the website dashboard."); } catch (Throwable) {}
        }

        return response()->json(['ok' => true]);
    }
}
