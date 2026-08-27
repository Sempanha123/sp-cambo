<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
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
        $callbackId = is_array($callback) ? (string) data_get($callback, 'id', '') : '';

        if ($chatId === '' || $telegramUserId === '') return response()->json(['ok' => true]);
        if ($chatType !== 'private') {
            try { $bot->sendMessage($chatId, 'SP Cambo Store is available in a private chat with this bot.'); } catch (Throwable) {}
            return response()->json(['ok' => true]);
        }

        try {
            // Legacy website linking remains accepted, but normal shopping never requires it.
            if (! is_array($callback)) {
                $legacyText = trim((string) data_get($message, 'text', ''));
                [$legacyCommand, $legacyArgument] = $this->command($legacyText);
                if ($legacyCommand === '/link' && $legacyArgument !== '') {
                    $telegram->link($legacyArgument, $telegramUserId, $chatId, is_string($username) ? $username : null);
                    $bot->sendMessage($chatId, 'Website account linked. Use the Store button to continue.');
                    return response()->json(['ok' => true]);
                }
                if (in_array($legacyCommand, ['/chatid', '/myid'], true)) {
                    $bot->sendMessage($chatId, "ℹ️ This is your SP Cambo Store Bot chat ID: {$chatId}.\n\nThe website does not send Telegram order alerts.");
                    return response()->json(['ok' => true]);
                }
            }

            $account = $telegram->ensureStorefrontAccount(
                $telegramUserId,
                $chatId,
                is_string($username) ? $username : null,
                $displayName !== '' ? $displayName : null,
            );

            if (is_array($callback)) {
                $this->handleCallback($telegram, $bot, $account, trim((string) data_get($callback, 'data', '')), $callbackId, $updateId);
                return response()->json(['ok' => true]);
            }

            $text = trim((string) data_get($message, 'text', ''));
            if ($text === '') return response()->json(['ok' => true]);
            [$command, $argument] = $this->command($text);
            $normalized = mb_strtolower($text);

            if ($command === '/start') {
                $startArg = mb_strtolower($argument);
                if ($startArg === 'store') {
                    $telegram->sendStorefront($account);
                } elseif (str_starts_with($startArg, 'package_')) {
                    $slug = substr($argument, strlen('package_'));
                    if ($slug !== '' && preg_match('/^[A-Za-z0-9_-]{1,48}$/', $slug) === 1) {
                        $package = \App\Models\Package::query()->published()->where('slug', $slug)->first();
                        if ($package) {
                            $telegram->sendProduct($account, (int) $package->id);
                        } else {
                            $telegram->sendStorefront($account);
                        }
                    } else {
                        $telegram->sendStorefront($account);
                    }
                } else {
                    $telegram->sendHome($account);
                }
            } elseif (in_array($command, ['/shop', '/plans', '/store'], true) || $this->matches($normalized, ['🛍 store', '🛍 ហាង', '🛍 buy package', '🛍 ទិញកញ្ចប់'])) {
                $telegram->sendStorefront($account);
            } elseif ($command === '/buy' && $argument !== '') {
                $telegram->beginPurchase($account, $argument, $updateId);
            } elseif ($command === '/check') {
                $purchase = $telegram->checkLatest($account);
                if (! $purchase) $bot->sendMessage($chatId, 'No Telegram purchase was found. Open Store to choose a package.');
                elseif ($purchase->delivered_at === null) $bot->sendMessage($chatId, 'Payment is not verified yet. SP Cambo will keep checking automatically.');
            } elseif ($command === '/balance' || $this->matches($normalized, ['💰 balance', '💰 my balance', '💰 សមតុល្យ', '💰 សមតុល្យរបស់ខ្ញុំ'])) {
                $telegram->sendBalance($account);
            } elseif ($command === '/keys' || $command === '/apikeys' || $this->matches($normalized, ['🔑 my api keys', '🔑 api keys របស់ខ្ញុំ'])) {
                $telegram->sendApiKeys($account);
            } elseif ($command === '/orders' || $this->matches($normalized, ['🧾 orders', '🧾 my orders', '🧾 ការបញ្ជាទិញ', '🧾 ការបញ្ជាទិញរបស់ខ្ញុំ', '📋 orders', '📋 ការបញ្ជាទិញ'])) {
                $telegram->sendOrders($account);
            } elseif ($command === '/models' || $this->matches($normalized, ['🧠 models', '🧠 ម៉ូដែល'])) {
                $telegram->sendModels($account);
            } elseif ($command === '/language' || $this->matches($normalized, ['🌐 language', '🌐 ភាសា'])) {
                $telegram->sendLanguage($account);
            } elseif ($command === '/support' || $this->matches($normalized, ['📞 support', '📞 ជំនួយ'])) {
                $telegram->sendSupport($account);
            } elseif ($command === '/updates' || $this->matches($normalized, ['🔔 updates', '🔔 ព័ត៌មានថ្មី', '📣 updates', '📣 ព័ត៌មានថ្មី'])) {
                $telegram->sendUpdatesStatus($account);
            } else {
                $telegram->sendHome($account);
            }
        } catch (Throwable $e) {
            report($e);
            if ($callbackId !== '') {
                try { $bot->answerCallbackQuery($callbackId, 'Could not complete that action.'); } catch (Throwable) {}
            }
            try { $bot->sendMessage($chatId, 'SP Cambo could not complete that action. Please use the Store menu and try again.'); } catch (Throwable) {}
        }

        return response()->json(['ok' => true]);
    }

    private function handleCallback(
        TelegramCommerceService $telegram,
        TelegramBotClient $bot,
        TelegramAccount $account,
        string $data,
        string $callbackId,
        string $updateId,
    ): void {
        $ack = static function (TelegramBotClient $bot, string $callbackId, ?string $text = null): void {
            if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, $text);
        };

        if ($data === 'noop') {
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'home') {
            $telegram->sendHome($account);
            $ack($bot, $callbackId, 'Home');
            return;
        }
        if ($data === 'store' || str_starts_with($data, 'store:')) {
            $page = $data === 'store' ? 1 : max(1, (int) substr($data, 6));
            $telegram->sendStorefront($account, $page);
            $ack($bot, $callbackId, 'Store opened');
            return;
        }
        if (str_starts_with($data, 'pkg:')) {
            $packageId = filter_var(substr($data, 4), FILTER_VALIDATE_INT);
            if ($packageId === false) throw new RuntimeException('That package button is invalid.');
            $telegram->sendProduct($account, (int) $packageId);
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'buy:')) {
            $packageId = filter_var(substr($data, 4), FILTER_VALIDATE_INT);
            if ($packageId === false) throw new RuntimeException('That purchase button is invalid.');
            $telegram->beginPurchaseByPackageId($account, (int) $packageId, $updateId);
            $ack($bot, $callbackId, 'Order created');
            return;
        }
        if (str_starts_with($data, 'check:')) {
            $purchase = $telegram->checkPurchase($account, substr($data, 6));
            if (! $purchase) {
                $bot->sendMessage($account->chat_id, 'That purchase was not found. Open Store and try again.');
            } elseif ($purchase->delivered_at === null) {
                $bot->sendMessage($account->chat_id, 'Payment is not verified yet. SP Cambo will keep checking automatically.');
            }
            $ack($bot, $callbackId, $purchase?->delivered_at ? 'Delivered' : 'Checked');
            return;
        }
        if ($data === 'balance') {
            $telegram->sendBalance($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'keys') {
            $telegram->sendApiKeys($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'orders') {
            $telegram->sendOrders($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'models') {
            $telegram->sendModels($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'language') {
            $telegram->sendLanguage($account);
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'lang:')) {
            $locale = substr($data, 5);
            $account = $telegram->setLocale($account, $locale);
            $ack($bot, $callbackId, $locale === 'km' ? 'បានប្ដូរភាសា' : 'Language updated');
            $telegram->sendHome($account);
            return;
        }
        if ($data === 'support') {
            $telegram->sendSupport($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'updates') {
            $telegram->sendUpdatesStatus($account);
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'updates:')) {
            $enabled = substr($data, 8) === 'on';
            $account = $telegram->setAnnouncements($account, $enabled);
            $ack($bot, $callbackId, $enabled ? 'Updates enabled' : 'Updates muted');
            $telegram->sendUpdatesStatus($account);
            return;
        }

        $ack($bot, $callbackId, 'This button is no longer available.');
    }

    /** @return array{0:string,1:string} */
    private function command(string $text): array
    {
        [$command, $argument] = array_pad(preg_split('/\s+/', trim($text), 2) ?: [], 2, '');
        $command = mb_strtolower(preg_replace('/@[^\s]+$/', '', $command) ?? $command);
        return [$command, trim($argument)];
    }

    /** @param array<int,string> $values */
    private function matches(string $value, array $values): bool
    {
        return in_array($value, $values, true);
    }
}
