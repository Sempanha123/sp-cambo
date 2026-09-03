<?php

namespace App\Services;

use App\Models\Package;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class TelegramStorefrontUiService
{
    private const STORE_PAGE_SIZE = 10;
    private const ORDER_PAGE_SIZE = 4;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramPendingOrderPolicy $pendingOrders,
    ) {}

    /**
     * Store navigation is category-first. Callback-driven navigation edits the
     * existing Telegram message so Previous / Next / product browsing does not
     * flood the customer's chat with duplicate catalog messages.
     */
    public function sendStorefront(
        TelegramAccount $account,
        int $page = 1,
        ?int $messageId = null,
        ?string $family = null,
    ): void {
        $family = mb_strtolower(trim((string) $family));

        if ($family === '') {
            $this->sendFamilyPicker($account, $messageId);
            return;
        }

        $this->sendFamilyPackages($account, $family, $page, $messageId);
    }

    private function sendFamilyPicker(TelegramAccount $account, ?int $messageId = null): void
    {
        $km = $this->isKhmer($account);
        $packages = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'family', 'family_label', 'sort_order']);

        if ($packages->isEmpty()) {
            $this->sendOrEdit(
                $account,
                $km
                    ? "🛍 SP Cambo Store\n\nមិនទាន់មានកញ្ចប់ដែលអាចទិញបានទេ។"
                    : "🛍 SP Cambo Store\n\nNo packages are available right now.",
                ['inline_keyboard' => [[['text' => '🏠 Home', 'callback_data' => 'home']]]],
                $messageId,
            );
            return;
        }

        $families = $packages
            ->groupBy(fn (Package $package): string => mb_strtolower(trim((string) $package->family)))
            ->map(function ($group, string $family): array {
                /** @var Package $first */
                $first = $group->first();
                return [
                    'family' => $family,
                    'label' => trim((string) ($first->family_label ?: $family)),
                    'count' => $group->count(),
                    'sort_order' => (int) $group->min('sort_order'),
                ];
            })
            ->filter(fn (array $row): bool => $row['family'] !== '')
            ->sortBy('sort_order')
            ->values();

        $text = $km
            ? "🛍✨ SP CAMBO STORE\n\nជ្រើសរើសប្រភេទ AI 👇\nបន្ទាប់មកជ្រើសកញ្ចប់ដែលអ្នកចង់ទិញ។"
            : "🛍✨ SP CAMBO STORE\n\nChoose an AI family 👇\nThen choose the package you want to buy.";

        $keyboard = [];
        foreach ($families as $row) {
            $keyboard[] = [[
                'text' => $this->familyIcon((string) $row['family']).' '.$row['label'].' · '.$row['count'].' packages',
                'callback_data' => 'family:'.$row['family'].':1',
            ]];
        }

        $keyboard[] = [
            ['text' => $km ? '🧠 ម៉ូដែល' : '🧠 Models', 'callback_data' => 'models'],
            ['text' => $km ? '🧾 ការទិញ' : '🧾 Orders', 'callback_data' => 'orders'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->sendOrEdit($account, $text, ['inline_keyboard' => $keyboard], $messageId);
    }

    private function sendFamilyPackages(
        TelegramAccount $account,
        string $family,
        int $page,
        ?int $messageId = null,
    ): void {
        $query = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->where('family', $family)
            ->orderBy('sort_order')
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->sendFamilyPicker($account, $messageId);
            return;
        }

        $pages = max(1, (int) ceil($total / self::STORE_PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $packages = $query->forPage($page, self::STORE_PAGE_SIZE)->get();
        /** @var Package|null $first */
        $first = $packages->first();
        $label = trim((string) ($first?->family_label ?: ucfirst($family)));
        $km = $this->isKhmer($account);

        $text = $km
            ? "{$this->familyIcon($family)} {$label}\n\nជ្រើសកញ្ចប់ 👇\n📦 បង្ហាញស្តុកនៅលើប៊ូតុងនីមួយៗ។"
            : "{$this->familyIcon($family)} {$label} PACKAGES\n\nChoose a package 👇\n📦 Stock is shown on every package button.";

        // Exactly one package button per row, up to 10 rows per page.
        $keyboard = [];
        foreach ($packages as $package) {
            $keyboard[] = [[
                'text' => $this->packageButtonLabel($package),
                'callback_data' => 'pkg:'.$package->id.':'.$page,
            ]];
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 1) {
                $nav[] = ['text' => '⬅️', 'callback_data' => 'family:'.$family.':'.($page - 1)];
            }
            $nav[] = ['text' => $page.'/'.$pages, 'callback_data' => 'noop'];
            if ($page < $pages) {
                $nav[] = ['text' => '➡️', 'callback_data' => 'family:'.$family.':'.($page + 1)];
            }
            $keyboard[] = $nav;
        }

        $keyboard[] = [
            ['text' => $km ? '⬅️ ប្រភេទ' : '⬅️ Categories', 'callback_data' => 'store:1'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->sendOrEdit($account, $text, ['inline_keyboard' => $keyboard], $messageId);
    }

    public function sendProduct(
        TelegramAccount $account,
        int $packageId,
        ?int $messageId = null,
        int $returnPage = 1,
    ): void {
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

        $family = mb_strtolower(trim((string) $package->family));
        $backCallback = $family !== ''
            ? 'family:'.$family.':'.max(1, $returnPage)
            : 'store:1';

        $this->sendOrEdit(
            $account,
            implode("\n", $lines),
            ['inline_keyboard' => [
                [[
                    'text' => $km ? '🛒 ទិញឥឡូវ' : '🛒 Buy Now',
                    'callback_data' => 'buy:'.$package->id,
                ]],
                [
                    ['text' => $km ? '⬅️ ត្រឡប់' : '⬅️ Back', 'callback_data' => $backCallback],
                    ['text' => '🏠 Home', 'callback_data' => 'home'],
                ],
            ]],
            $messageId,
        );
    }

    public function sendOrders(TelegramAccount $account, string $view = 'completed'): void
    {
        // Keep the list fresh even if the user opens Orders before the scheduled
        // cleanup tick. Only abandoned unpaid orders older than one hour can be
        // removed; paid/delivery-retry orders are protected.
        $this->pendingOrders->cleanupExpired($account, 50);

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
            $km ? 'អតិបរមា 4 · មិនបានបង់ផុតកំណត់ក្នុង 1 ម៉ោង' : 'Max 4 open orders · unpaid orders expire after 1 hour',
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
        $stock = '📦'.$this->stockButtonLabel($package);

        if ($this->isCreditPackage($package)) {
            return '💳 '.$family.' '.$this->creditDisplay($package).' · '.$this->packagePrice($package).' · '.$stock;
        }

        return '🪙 '.$family.' '.$this->compactUnits((int) $package->advertised_units).' · '.$this->packagePrice($package).' · '.$stock;
    }

    private function stockButtonLabel(Package $package): string
    {
        if ($package->stock_quantity === null) {
            return '∞';
        }

        return number_format(max(0, (int) $package->stock_quantity));
    }

    private function familyIcon(string $family): string
    {
        return match (mb_strtolower(trim($family))) {
            'claude' => '🟠',
            'deepseek' => '🔵',
            'codex', 'openai' => '🟢',
            'gemini' => '✨',
            default => '🤖',
        };
    }

    /** @param array<string,mixed> $replyMarkup */
    private function sendOrEdit(
        TelegramAccount $account,
        string $text,
        array $replyMarkup,
        ?int $messageId = null,
    ): void {
        if ($messageId !== null && $messageId > 0) {
            try {
                $this->bot->editMessageText($account->chat_id, $messageId, $text, $replyMarkup);
                return;
            } catch (RuntimeException $exception) {
                $message = mb_strtolower($exception->getMessage());

                if (str_contains($message, 'message is not modified')) {
                    return;
                }

                $notEditable = str_contains($message, 'message to edit not found')
                    || str_contains($message, "message can't be edited")
                    || str_contains($message, 'message_id_invalid');

                if (! $notEditable) {
                    throw $exception;
                }
            }
        }

        $this->bot->sendMessage($account->chat_id, $text, $replyMarkup);
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
