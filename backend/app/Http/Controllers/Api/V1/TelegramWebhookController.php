<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TelegramPendingOrderLimitException;
use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommerceService;
use App\Services\TelegramStorefrontUiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramCommerceService $telegram, TelegramStorefrontUiService $ui, TelegramBotClient $bot): JsonResponse
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
        $callbackMessageId = is_array($callback) ? (int) data_get($callback, 'message.message_id', 0) : 0;

        if ($chatId === '' || $telegramUserId === '') return response()->json(['ok' => true]);
        if ($chatType !== 'private') {
            try { $bot->sendMessage($chatId, 'SP Cambo Store is available in a private chat with this bot.'); } catch (Throwable) {}
            return response()->json(['ok' => true]);
        }

        try {
            if (! is_array($callback)) {
                $legacyText = trim((string) data_get($message, 'text', ''));
                [$legacyCommand, $legacyArgument] = $this->command($legacyText);
                if ($legacyCommand === '/link' && $legacyArgument !== '') {
                    $telegram->link($legacyArgument, $telegramUserId, $chatId, is_string($username) ? $username : null);
                    $bot->sendMessage($chatId, 'Website account linked. Use the Store button to continue.');
                    return response()->json(['ok' => true]);
                }
                if (in_array($legacyCommand, ['/chatid', '/myid'], true)) {
                    $bot->sendMessage($chatId, "ℹ️ This is your SP Cambo Store Bot chat ID: {$chatId}.\n\nVerified paid + fulfilled website and Telegram purchases can be announced according to Admin → Telegram Store routing.");
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
                $this->handleCallback(
                    $telegram,
                    $ui,
                    $bot,
                    $account,
                    trim((string) data_get($callback, 'data', '')),
                    $callbackId,
                    $updateId,
                    $callbackMessageId,
                );
                return response()->json(['ok' => true]);
            }

            $text = trim((string) data_get($message, 'text', ''));
            if ($text === '') return response()->json(['ok' => true]);
            [$command, $argument] = $this->command($text);
            $normalized = mb_strtolower($text);

            if (($command === '/cancel' || ! str_starts_with($text, '/')) && $telegram->handlePromotionInput($account, $text)) {
                return response()->json(['ok' => true]);
            }

            if ($command === '/start') {
                $startArg = mb_strtolower($argument);
                if ($startArg === 'store') {
                    $ui->sendStorefront($account);
                } elseif (str_starts_with($startArg, 'package_')) {
                    $slug = substr($argument, strlen('package_'));
                    if ($slug !== '' && preg_match('/^[A-Za-z0-9_-]{1,48}$/', $slug) === 1) {
                        $package = \App\Models\Package::query()->published()->where('slug', $slug)->first();
                        if ($package) {
                            $ui->sendProduct($account, (int) $package->id);
                        } else {
                            $ui->sendStorefront($account);
                        }
                    } else {
                        $ui->sendStorefront($account);
                    }
                } else {
                    $telegram->sendCompactHome($account);
                }
            } elseif (in_array($command, ['/shop', '/plans', '/store'], true) || $this->matches($normalized, [
                '🛍 store', '🛍 ហាង', '🛍 buy', '🛍 ទិញ', '🛍 buy package', '🛍 ទិញកញ្ចប់', '🛍✨ buy package', '🛍✨ ទិញកញ្ចប់',
            ])) {
                $ui->sendStorefront($account);
            } elseif ($command === '/buy' && $argument !== '') {
                $package = \App\Models\Package::query()->published()->where('slug', trim($argument))->first();
                if (! $package || ! $package->auto_creates_api_key) {
                    throw new RuntimeException('That package is not available.');
                }
                $telegram->sendCheckout($account, (int) $package->id);
            } elseif ($command === '/check') {
                $purchase = $telegram->checkPurchaseAndNotify($account, 'latest');
                if (! $purchase) {
                    $bot->sendMessage($chatId, 'No Telegram purchase was found. Open Store to choose a package.');
                }
            } elseif ($command === '/balance' || $this->matches($normalized, ['💰 balance', '💰 my balance', '💰 សមតុល្យ', '💰 សមតុល្យរបស់ខ្ញុំ'])) {
                $telegram->sendBalance($account);
            } elseif ($command === '/history'
                || $command === '/keys'
                || $command === '/apikeys'
                || $command === '/orders'
                || $this->matches($normalized, [
                    '🧾✨ history', '🧾 history', 'history',
                    '🧾✨ ប្រវត្តិ', '🧾 ប្រវត្តិ', 'ប្រវត្តិ',
                    '🔑 api keys', '🔑 my api keys', '🔑 api keys របស់ខ្ញុំ',
                    '🧾 orders', '🧾 my orders', '🧾 ការបញ្ជាទិញ', '🧾 ការបញ្ជាទិញរបស់ខ្ញុំ',
                    '📋 orders', '📋 ការបញ្ជាទិញ',
                ])) {
                $ui->sendHistory($account);
            } elseif ($command === '/models' || $this->matches($normalized, ['🧠 models', '🧠 ម៉ូដែល'])) {
                $telegram->sendModels($account);
            } elseif ($command === '/language' || $this->matches($normalized, ['🌐 language', '🌐 ភាសា'])) {
                $telegram->sendLanguage($account);
            } elseif ($command === '/wallet' || $this->matches($normalized, ['👛 wallet', '👛 store wallet', '👛✨ store wallet'])) {
                $telegram->sendStoreWallet($account);
            } elseif ($command === '/topup' || $this->matches($normalized, ['➕💵 add money', '➕ add money', '➕💵 បញ្ចូលប្រាក់'])) {
                $telegram->sendWalletTopupOptions($account);
            } elseif ($command === '/support' || $this->matches($normalized, ['📞 support', '📞 ជំនួយ'])) {
                $telegram->sendSupport($account);
            } elseif ($command === '/updates' || $this->matches($normalized, ['🔔 updates', '🔔 ព័ត៌មាន', '🔔 ព័ត៌មានថ្មី', '📣 updates', '📣 ព័ត៌មានថ្មី'])) {
                $telegram->sendUpdatesStatus($account);
            } else {
                $telegram->sendCompactHome($account);
            }
        } catch (TelegramPendingOrderLimitException $e) {
            if ($callbackId !== '') {
                try { $bot->answerCallbackQuery($callbackId, '4 open orders maximum'); } catch (Throwable) {}
            }
            try {
                $bot->sendMessage($chatId, '🧾 '.$e->getMessage(), [
                    'inline_keyboard' => [[
                        ['text' => '🧾✨ History', 'callback_data' => 'history'],
                        ['text' => '🏠 Home', 'callback_data' => 'home'],
                    ]],
                ]);
            } catch (Throwable) {}
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
        TelegramStorefrontUiService $ui,
        TelegramBotClient $bot,
        TelegramAccount $account,
        string $data,
        string $callbackId,
        string $updateId,
        int $messageId,
    ): void {
        $ack = static function (TelegramBotClient $bot, string $callbackId, ?string $text = null): void {
            if ($callbackId !== '') $bot->answerCallbackQuery($callbackId, $text);
        };

        if ($data === 'noop') {
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'home') {
            $telegram->sendCompactHome($account);
            $ack($bot, $callbackId, 'Home');
            return;
        }
        if ($data === 'store' || str_starts_with($data, 'store:')) {
            $ui->sendStorefront($account, 1, $messageId);
            $ack($bot, $callbackId, 'Choose a model family');
            return;
        }
        if (str_starts_with($data, 'family:')) {
            [, $family, $pageValue] = array_pad(explode(':', $data, 3), 3, '1');
            $family = mb_strtolower(trim($family));
            if ($family === '' || preg_match('/^[a-z0-9_-]{1,32}$/', $family) !== 1) {
                throw new RuntimeException('That model family button is invalid.');
            }
            $ui->sendStorefront($account, max(1, (int) $pageValue), $messageId, $family);
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'pkg:')) {
            [, $packageValue, $pageValue] = array_pad(explode(':', $data, 3), 3, '1');
            $packageId = filter_var($packageValue, FILTER_VALIDATE_INT);
            if ($packageId === false) throw new RuntimeException('That package button is invalid.');
            $ui->sendProduct($account, (int) $packageId, $messageId, max(1, (int) $pageValue));
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'buy:')) {
            $packageId = filter_var(substr($data, 4), FILTER_VALIDATE_INT);
            if ($packageId === false) throw new RuntimeException('That purchase button is invalid.');
            $telegram->sendCheckout($account, (int) $packageId);
            $ack($bot, $callbackId, 'Choose payment');
            return;
        }
        if (str_starts_with($data, 'promo:')) {
            $telegram->requestPromotionCode($account, substr($data, 6));
            $ack($bot, $callbackId, 'Send promotion code');
            return;
        }
        if (str_starts_with($data, 'promoclear:')) {
            $telegram->clearPromotionCode($account, substr($data, 11));
            $ack($bot, $callbackId, 'Promotion removed');
            return;
        }
        if (str_starts_with($data, 'promocancel:')) {
            $telegram->cancelPromotionInput($account, substr($data, 12));
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'promoskip:')) {
            $telegram->skipPromotionInput($account, substr($data, 10));
            $ack($bot, $callbackId, 'Continuing without promo');
            return;
        }
        if (str_starts_with($data, 'payw:')) {
            $telegram->beginCheckout($account, substr($data, 5), 'WALLET', $updateId);
            $ack($bot, $callbackId, 'Wallet payment');
            return;
        }
        if (str_starts_with($data, 'payq:')) {
            $telegram->beginCheckout($account, substr($data, 5), 'KHQR', $updateId);
            $ack($bot, $callbackId, 'KHQR ready');
            return;
        }
        if ($data === 'wallet') {
            $telegram->sendStoreWallet($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'wallet:topup') {
            $telegram->sendWalletTopupOptions($account);
            $ack($bot, $callbackId);
            return;
        }
        if (str_starts_with($data, 'topup:')) {
            $amount = filter_var(substr($data, 6), FILTER_VALIDATE_INT);
            if ($amount === false) throw new RuntimeException('That top-up amount is invalid.');
            $telegram->beginWalletTopup($account, (int) $amount);
            $ack($bot, $callbackId, 'KHQR ready');
            return;
        }
        if (str_starts_with($data, 'checktopup:')) {
            $topup = $telegram->checkWalletTopup($account, substr($data, 11));
            $ack($bot, $callbackId, $topup?->status === 'PAID' ? 'Wallet credited' : 'Checked');
            return;
        }
        if (str_starts_with($data, 'check:')) {
            $purchase = $telegram->checkPurchaseAndNotify($account, substr($data, 6));
            if (! $purchase) {
                $bot->sendMessage($account->chat_id, 'That purchase was not found. Open Store and try again.');
            }
            $ack(
                $bot,
                $callbackId,
                $purchase?->delivered_at !== null || $purchase?->status === 'DELIVERED'
                    ? 'Delivered'
                    : (in_array((string) ($purchase?->status ?? ''), ['PAID', 'DELIVERY_FAILED'], true) ? 'Paid · retrying delivery' : 'Checked')
            );
            return;
        }
        if ($data === 'balance') {
            $telegram->sendBalance($account);
            $ack($bot, $callbackId);
            return;
        }
        if ($data === 'history'
            || $data === 'keys'
            || $data === 'orders'
            || $data === 'orders:completed'
            || $data === 'orders:pending') {
            $ui->sendHistory($account, $messageId);
            $ack($bot, $callbackId, 'History');
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
            $telegram->sendCompactHome($account);
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
