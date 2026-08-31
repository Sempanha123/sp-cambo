<?php

namespace App\Services;

use App\Models\Package;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class TelegramStorefrontUiService
{
    private const STORE_PAGE_SIZE = 6;
    private const ORDER_PAGE_SIZE = 5;

    public function __construct(
        private readonly TelegramBotClient $bot,
    ) {}

    public function sendStorefront(TelegramAccount $account, int $page = 1): void
    {
        $query = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id');

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::STORE_PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $packages = $query->forPage($page, self::STORE_PAGE_SIZE)->get();
        $km = $this->isKhmer($account);

        if ($packages->isEmpty()) {
            $this->bot->sendMessage(
                $account->chat_id,
                $km
                    ? "🛍 SP Cambo Store\n\nមិនទាន់មានកញ្ចប់ដែលអាចទិញបានទេ។"
                    : "🛍 SP Cambo Store\n\nNo packages are available right now.",
                ['inline_keyboard' => [[
                    ['text' => '🏠 Home', 'callback_data' => 'home'],
                ]]],
            );

            return;
        }

        // Keep the message short. The package buttons themselves show the
        // useful comparison information, so customers do not need to scroll
        // through the same catalog twice.
        $text = $km
            ? "🛍✨ SP CAMBO STORE\n\nជ្រើសកញ្ចប់ 👇\n🪙 Token package  •  💳 Credit package"
            : "🛍✨ SP CAMBO STORE\n\nChoose a package 👇\n🪙 Token package  •  💳 Credit package";

        $packageButtons = [];
        foreach ($packages as $package) {
            $packageButtons[] = [
                'text' => $this->packageButtonLabel($package),
                'callback_data' => 'pkg:'.$package->id,
            ];
        }

        // 2 columns x 3 rows for a normal six-package page.
        $keyboard = array_chunk($packageButtons, 2);

        if ($pages > 1) {
            $nav = [];
            if ($page > 1) {
                $nav[] = ['text' => '⬅️', 'callback_data' => 'store:'.($page - 1)];
            }
            $nav[] = ['text' => $page.'/'.$pages, 'callback_data' => 'noop'];
            if ($page < $pages) {
                $nav[] = ['text' => '➡️', 'callback_data' => 'store:'.($page + 1)];
            }
            $keyboard[] = $nav;
        }

        $keyboard[] = [
            ['text' => $km ? '🧠 ម៉ូដែល' : '🧠 Models', 'callback_data' => 'models'],
            ['text' => $km ? '🧾 ការទិញ' : '🧾 Orders', 'callback_data' => 'orders'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->bot->sendMessage(
            $account->chat_id,
            $text,
            ['inline_keyboard' => $keyboard],
        );
    }

    public function sendProduct(TelegramAccount $account, int $packageId): void
    {
        $package = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->find($packageId);

        if (! $package) {
            throw new RuntimeException('That package is no longer available.');
        }

        $km = $this->isKhmer($account);
        $credit = $this->isCreditPackage($package);
        $models = $package->modelAliases()
            ->published()
            ->orderBy('display_name')
            ->pluck('public_alias')
            ->implode(', ');

        $stock = $package->stock_quantity === null
            ? ($km ? 'គ្មានកំណត់' : 'Unlimited')
            : number_format(max(0, (int) $package->stock_quantity));

        $lines = [
            ($credit ? '💳 ' : '🪙 ').$package->name,
            '',
            '💵 '.($km ? 'តម្លៃ' : 'Price').': '.$this->packagePrice($package),
        ];

        if ($credit) {
            // Credit packages intentionally never expose their internal token backing.
            $lines[] = '💳 '.($km ? 'Credit' : 'Credits').': '.$this->creditDisplay($package);
        } else {
            $lines[] = '🪙 '.($km ? 'Token' : 'Tokens').': '.number_format((int) $package->advertised_units);
        }

        $lines[] = '⏱ '.($km ? 'សុពលភាព' : 'Validity').': '.$this->durationLabel((int) $package->duration_seconds);
        $lines[] = '📦 '.($km ? 'ស្តុក' : 'Stock').': '.$stock;

        if ($models !== '') {
            $lines[] = '🧠 '.($km ? 'ម៉ូដែល' : 'Models').': '.$models;
        }

        $lines[] = '';
        $lines[] = $credit
            ? ($km
                ? 'ℹ️ SP Cambo usage credit សម្រាប់ប្រើ AI។ មិនមែនជាសាច់ប្រាក់ និងមិនអាចដកជាប្រាក់បានទេ។'
                : 'ℹ️ SP Cambo usage credit for AI access. It is not cash and cannot be withdrawn.')
            : ($km
                ? 'ℹ️ Token quota សម្រាប់ប្រើជាមួយម៉ូដែលខាងលើ។'
                : 'ℹ️ Token quota for the models shown above.');

        $this->bot->sendMessage(
            $account->chat_id,
            implode("\n", $lines),
            ['inline_keyboard' => [
                [[
                    'text' => $km ? '🛒 ទិញឥឡូវ' : '🛒 Buy Now',
                    'callback_data' => 'buy:'.$package->id,
                ]],
                [
                    ['text' => $km ? '⬅️ ហាង' : '⬅️ Store', 'callback_data' => 'store:1'],
                    ['text' => '🏠 Home', 'callback_data' => 'home'],
                ],
            ]],
        );
    }

    public function sendOrders(TelegramAccount $account, string $view = 'completed'): void
    {
        if ($view === 'pending') {
            $this->sendPendingOrders($account);
            return;
        }

        $this->sendCompletedOrders($account);
    }

    private function sendCompletedOrders(TelegramAccount $account): void
    {
        $km = $this->isKhmer($account);

        $completed = TelegramPurchase::query()
            ->where('telegram_account_id', $account->id)
            ->where(function (Builder $query): void {
                $query->whereNotNull('delivered_at')
                    ->orWhere('status', 'DELIVERED');
            })
            ->with('order.items')
            ->latest('delivered_at')
            ->limit(self::ORDER_PAGE_SIZE)
            ->get();

        $pendingCount = $this->pendingQuery($account)->count();

        if ($completed->isEmpty()) {
            $text = $km
                ? "🧾✨ ការទិញរបស់ខ្ញុំ\n\nមិនទាន់មានការទិញដែលបានបញ្ចប់ទេ។"
                : "🧾✨ MY PURCHASES\n\nNo completed purchases yet.";

            if ($pendingCount > 0) {
                $text .= $km
                    ? "\n\n⏳ មាន {$pendingCount} ការទូទាត់/ការបញ្ជាទិញកំពុងរង់ចាំ។"
                    : "\n\n⏳ {$pendingCount} payment/order".($pendingCount === 1 ? ' is' : 's are').' still pending.';
            }

            $keyboard = [];
            if ($pendingCount > 0) {
                $keyboard[] = [[
                    'text' => '⏳ '.($km ? 'កំពុងរង់ចាំ' : 'Pending').' ('.$pendingCount.')',
                    'callback_data' => 'orders:pending',
                ]];
            }
            $keyboard[] = [
                ['text' => $km ? '🛍 ទិញ' : '🛍 Buy', 'callback_data' => 'store:1'],
                ['text' => '🏠 Home', 'callback_data' => 'home'],
            ];

            $this->bot->sendMessage(
                $account->chat_id,
                $text,
                ['inline_keyboard' => $keyboard],
            );

            return;
        }

        $lines = [
            $km ? '✅✨ ការទិញបានជោគជ័យ' : '✅✨ COMPLETED PURCHASES',
            '',
        ];

        foreach ($completed as $index => $purchase) {
            $order = $purchase->order;
            $name = $order?->items?->first()?->package_name ?? 'Package';
            $reference = $this->shortReference((string) ($order?->reference ?? ''));
            $date = $purchase->delivered_at?->format('M j, Y')
                ?? $purchase->updated_at?->format('M j, Y')
                ?? '—';

            $lines[] = ($index + 1).'. ✅ '.$name;
            $lines[] = '   '.$date.($reference !== '' ? ' · '.$reference : '');
        }

        $keyboard = [];

        if ($pendingCount > 0) {
            $keyboard[] = [[
                'text' => '⏳ '.($km ? 'កំពុងរង់ចាំ' : 'Pending').' ('.$pendingCount.')',
                'callback_data' => 'orders:pending',
            ]];
        }

        $keyboard[] = [
            ['text' => $km ? '🛍 ទិញម្តងទៀត' : '🛍 Buy Again', 'callback_data' => 'store:1'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->bot->sendMessage(
            $account->chat_id,
            implode("\n", $lines),
            ['inline_keyboard' => $keyboard],
        );
    }

    private function sendPendingOrders(TelegramAccount $account): void
    {
        $km = $this->isKhmer($account);

        $pending = $this->pendingQuery($account)
            ->with('order.items')
            ->latest()
            ->limit(self::ORDER_PAGE_SIZE)
            ->get();

        if ($pending->isEmpty()) {
            $this->bot->sendMessage(
                $account->chat_id,
                $km
                    ? "⏳ មិនមានការបញ្ជាទិញកំពុងរង់ចាំទេ។"
                    : "⏳ No pending orders.",
                ['inline_keyboard' => [[
                    ['text' => $km ? '✅ បានបញ្ចប់' : '✅ Completed', 'callback_data' => 'orders:completed'],
                    ['text' => '🏠 Home', 'callback_data' => 'home'],
                ]]],
            );

            return;
        }

        $lines = [
            $km ? '⏳✨ ការបញ្ជាទិញកំពុងរង់ចាំ' : '⏳✨ PENDING ORDERS',
            '',
        ];

        $checkButtons = [];

        foreach ($pending as $index => $purchase) {
            $order = $purchase->order;
            $name = $order?->items?->first()?->package_name ?? 'Package';
            $price = $order
                ? $this->money(
                    (int) $order->total_minor,
                    (string) $order->currency,
                    (int) $order->currency_exponent,
                )
                : '';
            $reference = $this->shortReference((string) ($order?->reference ?? ''));

            $lines[] = ($index + 1).'. '.$this->pendingIcon($purchase).' '.$name;
            $lines[] = '   '.$this->pendingLabel($purchase, $km)
                .($price !== '' ? ' · '.$price : '')
                .($reference !== '' ? ' · '.$reference : '');

            $checkButtons[] = [
                'text' => '🔄 '.($km ? 'ពិនិត្យ' : 'Check').' #'.($index + 1),
                'callback_data' => 'check:'.$purchase->id,
            ];
        }

        $keyboard = array_chunk($checkButtons, 2);
        $keyboard[] = [
            ['text' => $km ? '✅ បានបញ្ចប់' : '✅ Completed', 'callback_data' => 'orders:completed'],
            ['text' => $km ? '🛍 ហាង' : '🛍 Store', 'callback_data' => 'store:1'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->bot->sendMessage(
            $account->chat_id,
            implode("\n", $lines),
            ['inline_keyboard' => $keyboard],
        );
    }

    private function pendingQuery(TelegramAccount $account): Builder
    {
        return TelegramPurchase::query()
            ->where('telegram_account_id', $account->id)
            ->whereNull('delivered_at')
            ->whereIn('status', [
                'AWAITING_PAYMENT',
                'PAID',
                'DELIVERY_FAILED',
            ]);
    }

    private function pendingIcon(TelegramPurchase $purchase): string
    {
        return match ((string) $purchase->status) {
            'PAID' => '✅',
            'DELIVERY_FAILED' => '⚠️',
            default => '⏳',
        };
    }

    private function pendingLabel(TelegramPurchase $purchase, bool $km): string
    {
        return match ((string) $purchase->status) {
            'PAID' => $km ? 'បានបង់ · កំពុងរៀបចំ API access' : 'Paid · Preparing API access',
            'DELIVERY_FAILED' => $km ? 'បានបង់ · កំពុងព្យាយាមផ្ញើម្ដងទៀត' : 'Paid · Delivery retry pending',
            default => $km ? 'កំពុងរង់ចាំការទូទាត់' : 'Waiting for payment',
        };
    }

    private function packageButtonLabel(Package $package): string
    {
        $family = trim((string) ($package->family_label ?: $package->name));
        $family = preg_replace('/\s+(credits?|tokens?)$/i', '', $family) ?: $family;
        $family = mb_substr($family, 0, 12);

        if ($this->isCreditPackage($package)) {
            return '💳 '.$family.' '.$this->creditDisplay($package).' · '.$this->packagePrice($package);
        }

        return '🪙 '.$family.' '.$this->compactUnits((int) $package->advertised_units).' · '.$this->packagePrice($package);
    }

    private function isCreditPackage(Package $package): bool
    {
        $rules = is_array($package->billing_rules) ? $package->billing_rules : [];

        return mb_strtoupper(trim((string) ($rules['package_kind'] ?? ''))) === 'SP_CREDITS';
    }

    private function creditDisplay(Package $package): string
    {
        $rules = is_array($package->billing_rules) ? $package->billing_rules : [];
        $credits = $rules['display_units'] ?? null;

        if (is_numeric($credits)) {
            return '$'.number_format((float) $credits, ((float) $credits === floor((float) $credits)) ? 0 : 2);
        }

        // Safe display fallback if an older credit package has no explicit
        // display_units field. Do not expose advertised_units because that field
        // is the internal quota backing for credit packages.
        return '$—';
    }

    private function compactUnits(int $units): string
    {
        if ($units >= 1_000_000_000 && $units % 1_000_000_000 === 0) {
            return number_format($units / 1_000_000_000).'B';
        }

        if ($units >= 1_000_000 && $units % 1_000_000 === 0) {
            return number_format($units / 1_000_000).'M';
        }

        if ($units >= 1_000 && $units % 1_000 === 0) {
            return number_format($units / 1_000).'K';
        }

        return number_format($units);
    }

    private function packagePrice(Package $package): string
    {
        return $this->money(
            (int) $package->price_minor,
            (string) $package->currency,
            (int) $package->currency_exponent,
        );
    }

    private function money(int $minor, string $currency, int $exponent): string
    {
        $exponent = max(0, min(6, $exponent));
        $divisor = 10 ** $exponent;
        $amount = $divisor > 0 ? $minor / $divisor : $minor;
        $number = number_format($amount, $exponent, '.', ',');

        return mb_strtoupper($currency) === 'USD'
            ? '$'.$number
            : mb_strtoupper($currency).' '.$number;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        if ($seconds % 86_400 === 0) {
            $days = intdiv($seconds, 86_400);
            return $days.' day'.($days === 1 ? '' : 's');
        }

        if ($seconds % 3_600 === 0) {
            $hours = intdiv($seconds, 3_600);
            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return number_format($seconds).' sec';
    }

    private function shortReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        return '#'.mb_substr($reference, -8);
    }

    private function isKhmer(TelegramAccount $account): bool
    {
        return $account->locale === 'km';
    }
}
