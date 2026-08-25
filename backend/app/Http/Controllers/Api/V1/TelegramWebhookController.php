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
        $callback = $request->input('callback_query');
        $message = is_array($callback) ? data_get($callback, 'message') : $request->input('message');
        if (! is_array($message)) return response()->json(['ok' => true]);

        $from = is_array($callback) ? data_get($callback, 'from') : data_get($message, 'from');
        if (! is_array($from)) return response()->json(['ok' => true]);

        $chatId = (string) data_get($message, 'chat.id', '');
        $chatType = (string) data_get($message, 'chat.type', '');
        $telegramUserId = (string) data_get($from, 'id', '');
        $username = data_get($from, 'username');
        $firstName = trim((string) data_get($from, 'first_name', ''));
        $lastName = trim((string) data_get($from, 'last_name', ''));
        $displayName = trim($firstName.' '.$lastName);

        if ($chatId === '' || $telegramUserId === '') return response()->json(['ok' => true]);
        if ($chatType !== 'private') {
            try { $bot->sendMessage($chatId, 'SP Cambo Store purchases are available only in a private chat with this bot.'); } catch (Throwable) {}
            return response()->json(['ok' => true]);
        }

        $callbackId = is_array($callback) ? (string) data_get($callback, 'id', '') : '';

        try {
            // Backwards compatibility only: an old explicit /link command must run
            // before automatic storefront provisioning, otherwise the new Telegram-
            // only workspace would occupy the identity first. It is not advertised.
            if (! is_array($callback)) {
                $legacyText = trim((string) data_get($message, 'text', ''));
                [$legacyCommand, $legacyArgument] = array_pad(preg_split('/\s+/', $legacyText, 2) ?: [], 2, '');
                $legacyCommand = mb_strtolower(preg_replace('/@[^\s]+$/', '', $legacyCommand) ?? $legacyCommand);
                if ($legacyCommand === '/link' && trim($legacyArgument) !== '') {
                    $telegram->link(trim($legacyArgument), $telegramUserId, $chatId, is_string($username) ? $username : null);
                    $bot->sendMessage($chatId, 'Website account linked. Send /shop to open the SP Cambo Store.');
                    return response()->json(['ok' => true]);
                }
            }

            // Every private Telegram customer can shop directly. No website-link code is required.
            $account = $telegram->ensureStorefrontAccount(
                $telegramUserId,
                $chatId,
                is_string($username) ? $username : null,
                $displayName !== '' ? $displayName : null,
            );

            if (is_array($callback)) {
                $data = trim((string) data_get($callback, 'data', ''));

                if ($data === 'store') {
                    $telegram->sendStorefront($account);
                    if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, 'Store opened');
                    return response()->json(['ok' => true]);
                }

                if (str_starts_with($data, 'buy:')) {
                    $packageId = filter_var(substr($data, 4), FILTER_VALIDATE_INT);
                    if ($packageId === false) throw new \RuntimeException('That product button is invalid.');
                    $telegram->beginPurchaseByPackageId($account, (int) $packageId, $updateId);
                    if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, 'Order created');
                    return response()->json(['ok' => true]);
                }

                if (str_starts_with($data, 'check:')) {
                    $purchase = $telegram->checkPurchase($account, substr($data, 6));
                    if (! $purchase) {
                        $bot->sendMessage($chatId, 'That purchase was not found. Open the store and try again.');
                    } elseif ($purchase->delivered_at === null) {
                        $bot->sendMessage($chatId, 'Payment is not verified yet. The server will keep checking automatically.');
                    }
                    if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, $purchase?->delivered_at ? 'Delivered' : 'Checked');
                    return response()->json(['ok' => true]);
                }

                if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, 'This button is no longer available.');
                return response()->json(['ok' => true]);
            }

            $text = trim((string) data_get($message, 'text', ''));
            if ($text === '') return response()->json(['ok' => true]);
            [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');
            $command = mb_strtolower(preg_replace('/@[^\s]+$/', '', $command) ?? $command);

            if (in_array($command, ['/start', '/shop', '/plans'], true)) {
                $telegram->sendStorefront($account);
            } elseif ($command === '/buy' && trim($argument) !== '') {
                // Legacy command remains useful for power users; inline Buy buttons are primary.
                $telegram->beginPurchase($account, trim($argument), $updateId);
            } elseif ($command === '/check') {
                $purchase = $telegram->checkLatest($account);
                if (! $purchase) $bot->sendMessage($chatId, 'No Telegram purchase was found. Tap /shop to choose a product.');
                elseif ($purchase->delivered_at === null) $bot->sendMessage($chatId, 'Payment is not verified yet. The server will keep checking automatically.');
            } else {
                $bot->sendMessage($chatId, "SP Cambo Store\n\nTap /shop to browse products, buy with Bakong KHQR, and receive your API key automatically after payment verification.");
            }
        } catch (Throwable $e) {
            report($e);
            if ($callbackId !== '') {
                try { $bot->answerCallbackQuery($callbackId, 'Could not complete that action.'); } catch (Throwable) {}
            }
            try { $bot->sendMessage($chatId, 'SP Cambo could not complete that action. Please open /shop and try again.'); } catch (Throwable) {}
        }

        return response()->json(['ok' => true]);
    }
}
