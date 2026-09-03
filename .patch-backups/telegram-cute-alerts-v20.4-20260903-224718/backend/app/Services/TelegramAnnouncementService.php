<?php

namespace App\Services;

use App\Models\ModelAlias;
use App\Models\Order;
use App\Models\Package;
use App\Models\StoreWalletEntry;
use App\Models\Promotion;
use App\Models\TelegramAccount;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramAnnouncementDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramAnnouncementService
{
    public function __construct(private readonly TelegramBotClient $bot) {}

    public function packagePublished(Package $package, string $kind = 'NEW_PACKAGE'): TelegramAnnouncement
    {
        $package->loadMissing('modelAliases');
        $eventKey = 'package:'.strtolower($kind).':'.$package->id.':'.$package->updated_at?->format('YmdHis.u');
        $duration = $this->durationLabel((int) $package->duration_seconds);
        $models = $package->modelAliases->pluck('public_alias')->implode(', ');

        return $this->enqueue([
            'event_key' => $eventKey,
            'kind' => strtoupper($kind),
            'title' => $kind === 'NEW_PACKAGE' ? 'New package available' : 'Package updated',
            'body' => implode("\n", array_filter([
                $package->name,
                $package->subtitle,
                '💵 Price: '.$this->packagePrice($package),
                '🪙 Includes: '.number_format((int) $package->advertised_units).' '.$package->unit_label,
                '⏳ Validity: '.$duration,
                $package->stock_quantity === null ? '📦 Stock: Unlimited' : '📦 Stock: '.number_format((int) $package->stock_quantity),
                $models !== '' ? '🧠 Models: '.$models : null,
            ])),
            'package_id' => $package->id,
        ]);
    }

    public function packageStockAdded(Package $package, int $before, int $after): TelegramAnnouncement
    {
        $package->loadMissing('modelAliases');
        $kind = $before <= 0 ? 'RESTOCK' : 'STOCK_ADDED';
        $added = max(0, $after - $before);

        return $this->enqueue([
            'event_key' => 'package:stock:'.$package->id.':'.$after.':'.$package->updated_at?->format('YmdHis.u'),
            'kind' => $kind,
            'title' => $kind === 'RESTOCK' ? 'Package restocked' : 'Stock added',
            'body' => implode("
", array_filter([
                $package->name,
                $package->subtitle,
                '➕ Added: '.number_format($added),
                '📦 Available: '.number_format($after),
                '💵 Price: '.$this->packagePrice($package),
                '🛒✨ Tap Buy Now below while stock is available!',
            ])),
            'package_id' => $package->id,
            'metadata' => ['before_stock' => $before, 'after_stock' => $after, 'added_stock' => $added],
        ]);
    }

    public function promotionPublished(Promotion $promotion): TelegramAnnouncement
    {
        $promotion->loadMissing('packages');
        $package = $promotion->packages->first(fn (Package $candidate): bool => Package::query()->published()->whereKey($candidate->id)->exists());
        $offer = match ($promotion->type) {
            'PERCENTAGE' => number_format(((int) $promotion->percentage_bps) / 100, 2).'% off',
            'FIXED' => 'Save '.$this->promotionMoney($promotion, (int) $promotion->fixed_discount_minor),
            'PRICE_OVERRIDE' => 'Special price '.$this->promotionMoney($promotion, (int) $promotion->price_override_minor),
            'BONUS' => number_format((int) $promotion->bonus_units).' bonus units',
            'FREE' => 'Free offer',
            default => $promotion->label,
        };

        return $this->enqueue([
            'event_key' => 'promotion:published:'.$promotion->id.':'.$promotion->updated_at?->format('YmdHis.u'),
            'kind' => 'PROMOTION',
            'title' => 'Special offer',
            'body' => implode("
", array_filter([
                $promotion->label,
                '🎟️ Code: '.$promotion->code,
                '🎁 Offer: '.$offer,
                $promotion->ends_at ? '⏰ Ends: '.$promotion->ends_at->toAtomString() : null,
                '🛒💫 Tap Buy Now to grab an eligible package!',
            ])),
            'package_id' => $package?->id,
        ]);
    }

    public function modelPublished(ModelAlias $alias): TelegramAnnouncement
    {
        $alias->loadMissing('model.provider');
        $eventKey = 'model:published:'.$alias->id.':'.$alias->updated_at?->format('YmdHis.u');
        $provider = $alias->model?->provider?->name;
        $package = Package::query()
            ->published()
            ->whereHas('modelAliases', fn ($query) => $query->whereKey($alias->id))
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->first();

        return $this->enqueue([
            'event_key' => $eventKey,
            'kind' => 'NEW_MODEL',
            'title' => 'New model available',
            'body' => implode("\n", array_filter([
                $alias->display_name,
                '🏷️ Alias: '.$alias->public_alias,
                $provider ? '🔌 Provider: '.$provider : null,
                $alias->description,
                '🛍️✨ Open the Store to see packages for this model!',
            ])),
            'model_alias_id' => $alias->id,
            'package_id' => $package?->id,
        ]);
    }

    public function manual(string $title, string $body, ?Package $package = null): TelegramAnnouncement
    {
        return $this->enqueue([
            'event_key' => 'manual:'.Str::lower((string) Str::ulid()),
            'kind' => 'MANUAL',
            'title' => trim($title),
            'body' => trim($body),
            'package_id' => $package?->id,
        ]);
    }

    /**
     * Unified website + Telegram purchase activity. This method re-validates
     * payment and fulfillment independently so callers cannot manufacture social proof.
     */
    public function purchaseActivity(Order $order, ?TelegramAccount $excludedBuyer = null): ?TelegramAnnouncement
    {
        if (! (bool) config('services.telegram.purchase_activity_enabled', true)) {
            return null;
        }

        $order->loadMissing(['items', 'user', 'paymentAttempts']);
        if ($order->status !== 'FULFILLED' || $order->fulfilled_at === null || (int) $order->total_minor <= 0) {
            return null;
        }
        $paidByBakong = $order->paymentAttempts->contains(
            fn ($attempt): bool => $attempt->status === 'PAID' && $attempt->paid_at !== null
        );
        $paidByWallet = StoreWalletEntry::query()
            ->where('type', 'PURCHASE')
            ->where('source_type', 'ORDER')
            ->where('source_id', (string) $order->id)
            ->exists();
        if (! $paidByBakong && ! $paidByWallet) {
            return null;
        }

        $item = $order->items->first();
        if (! $item) {
            return null;
        }

        $snapshot = is_array($item->package_snapshot) ? $item->package_snapshot : [];
        $exponent = max(0, (int) $order->currency_exponent);
        $scale = 10 ** $exponent;
        $customer = $this->maskedCustomerName((string) ($order->user?->name ?: 'Customer'));
        $advertisedUnits = $snapshot['advertised_units'] ?? null;
        $quotaUnits = is_numeric($advertisedUnits)
            ? number_format((int) $advertisedUnits)
            : trim((string) $advertisedUnits);
        $quota = trim($quotaUnits.' '.trim((string) ($snapshot['unit_label'] ?? '')));

        return $this->enqueue([
            'event_key' => 'r13:public:order:'.$order->id.':subscribers',
            'kind' => 'PURCHASE_ACTIVITY',
            'title' => 'NEW ORDER!',
            'body' => 'Verified fulfilled SP Cambo purchase activity',
            'package_id' => $item->package_id,
            'metadata' => [
                'masked_customer' => $customer,
                'package_name' => (string) ($item->package_name ?: 'SP Cambo package'),
                'package_slug' => (string) ($item->package_slug ?: ''),
                'price' => number_format((int) $order->total_minor / $scale, $exponent, '.', ''),
                'currency' => strtoupper((string) $order->currency),
                'quota' => $quota !== '' ? $quota : '—',
                'validity' => $this->durationLabel((int) ($snapshot['duration_seconds'] ?? 0)),
            ],
            'excluded_telegram_account_id' => $excludedBuyer?->id,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function enqueue(array $attributes): TelegramAnnouncement
    {
        return TelegramAnnouncement::query()->firstOrCreate(
            ['event_key' => (string) $attributes['event_key']],
            [
                'kind' => (string) $attributes['kind'],
                'title' => (string) $attributes['title'],
                'body' => (string) $attributes['body'],
                'metadata' => $attributes['metadata'] ?? null,
                'package_id' => $attributes['package_id'] ?? null,
                'model_alias_id' => $attributes['model_alias_id'] ?? null,
                'excluded_telegram_account_id' => $attributes['excluded_telegram_account_id'] ?? null,
                'status' => 'QUEUED',
            ]
        );
    }

    /** @return array{announcements:int,attempted:int,sent:int,failed:int} */
    public function dispatchPending(int $batch = 50): array
    {
        $batch = max(1, min($batch, 200));
        $remaining = $batch;
        $announcements = 0;
        $attempted = 0;
        $sent = 0;
        $failed = 0;

        $rows = TelegramAnnouncement::query()
            ->whereIn('status', ['QUEUED', 'SENDING'])
            ->orderBy('created_at')
            ->limit(10)
            ->get();

        foreach ($rows as $announcement) {
            if ($remaining <= 0) {
                break;
            }

            if ($announcement->kind === 'PURCHASE_ACTIVITY'
                && ! (bool) config('services.telegram.purchase_activity_enabled', true)) {
                TelegramAnnouncement::query()->whereKey($announcement->id)->update([
                    'status' => 'CANCELLED',
                    'finished_at' => now(),
                ]);
                continue;
            }

            if ($announcement->kind === 'PURCHASE_ACTIVITY') {
                $delay = max(0, (int) config('services.telegram.purchase_activity_min_delay_seconds', 5));
                if ($announcement->created_at?->copy()->addSeconds($delay)->isFuture()) {
                    continue;
                }

                $maxPerHour = max(1, (int) config('services.telegram.purchase_activity_max_per_hour', 30));
                $earlierStarted = TelegramAnnouncement::query()
                    ->where('kind', 'PURCHASE_ACTIVITY')
                    ->where('id', '!=', $announcement->id)
                    ->whereNotNull('started_at')
                    ->where('started_at', '>=', now()->subHour())
                    ->count();
                if ($announcement->started_at === null && $earlierStarted >= $maxPerHour) {
                    continue;
                }
            }

            $announcements++;
            $this->prepareDeliveries($announcement);

            $ids = TelegramAnnouncementDelivery::query()
                ->where('telegram_announcement_id', $announcement->id)
                ->whereIn('status', ['PENDING', 'SENDING', 'FAILED'])
                ->where(function ($query): void {
                    $query->whereNull('delivery_lease_expires_at')
                        ->orWhere('delivery_lease_expires_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id');

            foreach ($ids as $id) {
                $claim = $this->claimDelivery((int) $id);
                if ($claim === null) {
                    continue;
                }

                $attempted++;
                $remaining--;
                $delivery = $claim['delivery'];
                $lease = $claim['lease'];
                $account = $delivery->account;

                if (! $account || $account->revoked_at !== null || ! $account->announcements_enabled) {
                    TelegramAnnouncementDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('delivery_lease_token', $lease)
                        ->whereNotIn('status', ['SENT', 'SKIPPED'])
                        ->update([
                            'status' => 'SKIPPED',
                            'attempted_at' => now(),
                            'last_error' => null,
                            'delivery_lease_token' => null,
                            'delivery_lease_expires_at' => null,
                        ]);
                    continue;
                }

                try {
                    $message = $this->message($announcement, $account);
                    $this->bot->sendMessage($account->chat_id, $message['text'], $message['reply_markup']);

                    $updated = TelegramAnnouncementDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('delivery_lease_token', $lease)
                        ->where('status', 'SENDING')
                        ->update([
                            'status' => 'SENT',
                            'attempted_at' => now(),
                            'last_error' => null,
                            'delivery_lease_token' => null,
                            'delivery_lease_expires_at' => null,
                        ]);
                    if ($updated > 0) {
                        $sent++;
                    }
                } catch (Throwable $e) {
                    $updated = TelegramAnnouncementDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('delivery_lease_token', $lease)
                        ->where('status', 'SENDING')
                        ->update([
                            'status' => 'FAILED',
                            'attempted_at' => now(),
                            'last_error' => Str::limit($e->getMessage(), 1000),
                            'delivery_lease_token' => null,
                            'delivery_lease_expires_at' => null,
                        ]);
                    if ($updated > 0) {
                        report($e);
                        $failed++;
                    }
                }
            }

            $this->refreshStatus($announcement);
        }

        return compact('announcements', 'attempted', 'sent', 'failed');
    }

    /** @return array{delivery:TelegramAnnouncementDelivery,lease:string}|null */
    private function claimDelivery(int $deliveryId): ?array
    {
        return DB::transaction(function () use ($deliveryId): ?array {
            $delivery = TelegramAnnouncementDelivery::query()
                ->with(['account', 'announcement'])
                ->lockForUpdate()
                ->find($deliveryId);

            if (! $delivery || in_array($delivery->status, ['SENT', 'SKIPPED'], true)) {
                return null;
            }

            if ($delivery->delivery_lease_expires_at !== null && $delivery->delivery_lease_expires_at->isFuture()) {
                return null;
            }

            $announcement = $delivery->announcement;
            $maxAttempts = $announcement?->kind === 'PURCHASE_ACTIVITY'
                ? max(1, (int) config('services.telegram.announcement_max_attempts', 8))
                : 8;
            if ((int) $delivery->attempt_count >= $maxAttempts) {
                return null;
            }

            $lease = hash('sha256', Str::random(64));
            $delivery->forceFill([
                'status' => 'SENDING',
                'delivery_lease_token' => $lease,
                'delivery_lease_expires_at' => now()->addSeconds((int) config('services.telegram.announcement_lease_seconds', 120)),
                'attempt_count' => ((int) $delivery->attempt_count) + 1,
            ])->save();

            return ['delivery' => $delivery->fresh('account'), 'lease' => $lease];
        });
    }

    public function retryFailed(string $announcementId): int
    {
        return DB::transaction(function () use ($announcementId): int {
            $announcement = TelegramAnnouncement::query()->lockForUpdate()->findOrFail($announcementId);
            $updated = TelegramAnnouncementDelivery::query()
                ->where('telegram_announcement_id', $announcement->id)
                ->where('status', 'FAILED')
                ->update([
                    'status' => 'PENDING',
                    'last_error' => null,
                    'delivery_lease_token' => null,
                    'delivery_lease_expires_at' => null,
                ]);

            if ($updated > 0) {
                $announcement->forceFill(['status' => 'SENDING', 'finished_at' => null])->save();
            }

            return $updated;
        });
    }

    private function prepareDeliveries(TelegramAnnouncement $announcement): void
    {
        DB::transaction(function () use ($announcement): void {
            $locked = TelegramAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status === 'QUEUED') {
                $locked->forceFill(['status' => 'SENDING', 'started_at' => $locked->started_at ?? now()])->save();
            }

            TelegramAccount::query()
                ->whereNull('revoked_at')
                ->where('announcements_enabled', true)
                ->when($locked->excluded_telegram_account_id, fn ($query, $excluded) => $query->where('id', '!=', $excluded))
                ->select(['id'])
                ->orderBy('id')
                ->chunkById(500, function ($accounts) use ($locked): void {
                    $now = now();
                    $rows = $accounts->map(fn (TelegramAccount $account): array => [
                        'telegram_announcement_id' => $locked->id,
                        'telegram_account_id' => $account->id,
                        'status' => 'PENDING',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();
                    if ($rows !== []) {
                        DB::table('telegram_announcement_deliveries')->insertOrIgnore($rows);
                    }
                });

            $locked->forceFill([
                'recipient_count' => TelegramAnnouncementDelivery::query()
                    ->where('telegram_announcement_id', $locked->id)
                    ->count(),
            ])->save();
        });
    }

    private function refreshStatus(TelegramAnnouncement $announcement): void
    {
        $counts = TelegramAnnouncementDelivery::query()
            ->where('telegram_announcement_id', $announcement->id)
            ->selectRaw("SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) as sent_count")
            ->selectRaw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status IN ('PENDING', 'SENDING') THEN 1 ELSE 0 END) as pending_count")
            ->first();

        $pending = (int) ($counts?->pending_count ?? 0);
        $failed = (int) ($counts?->failed_count ?? 0);
        $maxAttempts = $announcement->kind === 'PURCHASE_ACTIVITY'
            ? max(1, (int) config('services.telegram.announcement_max_attempts', 8))
            : max(1, (int) config('services.telegram.announcement_max_attempts', 8));
        $retryableFailed = TelegramAnnouncementDelivery::query()
            ->where('telegram_announcement_id', $announcement->id)
            ->where('status', 'FAILED')
            ->where('attempt_count', '<', $maxAttempts)
            ->exists();
        $hasWork = $pending > 0 || $retryableFailed;

        TelegramAnnouncement::query()->whereKey($announcement->id)->update([
            'sent_count' => (int) ($counts?->sent_count ?? 0),
            'failed_count' => $failed,
            'status' => $hasWork ? 'SENDING' : ($failed > 0 ? 'COMPLETED_WITH_FAILURES' : 'COMPLETED'),
            'finished_at' => $hasWork ? null : now(),
        ]);
    }

    /** @return array{text:string,reply_markup:array<string,mixed>} */
    private function message(TelegramAnnouncement $announcement, TelegramAccount $account): array
    {
        $km = $account->locale === 'km';
        $icon = match ($announcement->kind) {
            'NEW_MODEL' => '🧠✨',
            'NEW_PACKAGE' => '📦💫',
            'PACKAGE_UPDATE' => '✨🛠️',
            'STOCK_ADDED' => '📥📦',
            'RESTOCK' => '✅🔥',
            'PROMOTION' => '🏷️🎁',
            'PURCHASE_ACTIVITY' => '🎉🛍️',
            default => '📣✨',
        };
        $heading = match ($announcement->kind) {
            'NEW_MODEL' => $km ? 'មានម៉ូដែលថ្មីហើយ!' : 'NEW MODEL IS HERE!',
            'NEW_PACKAGE' => $km ? 'មានកញ្ចប់ថ្មីហើយ!' : 'NEW PACKAGE JUST LANDED!',
            'PACKAGE_UPDATE' => $km ? 'កញ្ចប់បានអាប់ដេតហើយ!' : 'PACKAGE JUST GOT AN UPDATE!',
            'STOCK_ADDED' => $km ? 'បានបន្ថែមស្តុកថ្មី!' : 'MORE STOCK JUST ARRIVED!',
            'RESTOCK' => $km ? 'មានស្តុកវិញហើយ!' : 'BACK IN STOCK!',
            'PROMOTION' => $km ? 'ប្រូម៉ូសិនពិសេសមកដល់ហើយ!' : 'SPECIAL DEAL IS LIVE!',
            'PURCHASE_ACTIVITY' => $km ? 'មានការបញ្ជាទិញថ្មី!' : 'NEW ORDER JUST LANDED!',
            default => $announcement->title,
        };

        if ($announcement->kind === 'PURCHASE_ACTIVITY') {
            $meta = is_array($announcement->metadata) ? $announcement->metadata : [];
            $username = ltrim(trim((string) config('services.telegram.bot_username')), '@');
            $slug = (string) ($meta['package_slug'] ?? '');
            $start = 'store';
            if ($slug !== '' && Package::query()->published()->where('slug', $slug)->exists()) {
                $safeSlug = (string) preg_replace('/[^a-z0-9_-]/i', '', $slug);
                $start = 'package_'.Str::limit($safeSlug, 48, '');
            }
            $keyboard = [];
            if ($username !== '') {
                $keyboard[] = [[
                    'text' => $km ? '🛒✨ ទិញឥឡូវ' : '🛒✨ Buy Now',
                    'url' => 'https://t.me/'.$username.'?start='.$start,
                ]];
            } else {
                $keyboard[] = [[
                    'text' => $km ? '🛒✨ ទិញឥឡូវ' : '🛒✨ Buy Now',
                    'callback_data' => 'store:1',
                ]];
            }

        } else {
            $keyboard = [];
            if ($announcement->package_id !== null) {
                $keyboard[] = [[
                    'text' => $km ? '🛒✨ ទិញឥឡូវ' : '🛒✨ Buy Now',
                    'callback_data' => 'buy:'.$announcement->package_id,
                ]];
            }
            $keyboard[] = [[
                'text' => $km ? '🛍️✨ បើកហាង' : '🛍️✨ Open Store',
                'callback_data' => 'store:1',
            ]];

        }

        $body = $announcement->kind === 'PURCHASE_ACTIVITY'
            ? $this->purchaseActivityBody($announcement, $km)
            : $announcement->body;

        return [
            'text' => $icon.' '.$heading."\n\n".$body,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    private function purchaseActivityBody(TelegramAnnouncement $announcement, bool $km): string
    {
        $meta = is_array($announcement->metadata) ? $announcement->metadata : [];
        $values = [
            '{masked_customer}' => (string) ($meta['masked_customer'] ?? 'Cus***'),
            '{package_name}' => (string) ($meta['package_name'] ?? 'SP Cambo package'),
            '{price}' => (string) ($meta['price'] ?? '—'),
            '{currency}' => (string) ($meta['currency'] ?? ''),
            '{quota}' => (string) ($meta['quota'] ?? '—'),
            '{validity}' => (string) ($meta['validity'] ?? '—'),
        ];
        $configured = trim((string) config($km
            ? 'services.telegram.public_purchase_template_km'
            : 'services.telegram.public_purchase_template_en'));
        $template = $configured !== ''
            ? $configured
            : ($km
                ? "👤✨ {masked_customer} ទើបបានទិញ!\n📦🎁 {package_name}\n🪙💫 {quota}\n⏳🕒 {validity}\n💵💳 {price} {currency}\n✅🚀 បានប្រគល់ជូនដោយស្វ័យប្រវត្តិ\n\n🛒✨ ចង់បានដែរ? ចុច Buy Now ខាងក្រោម!"
                : "👤✨ {masked_customer} just grabbed a package!\n📦🎁 {package_name}\n🪙💫 {quota}\n⏳🕒 {validity}\n💵💳 {price} {currency}\n✅🚀 Delivered automatically\n\n🛒✨ Want one too? Tap Buy Now below!");

        return strtr($template, $values);
    }

    private function maskedCustomerName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strtolower($name) === 'customer') {
            return 'Cus***';
        }
        $firstWord = preg_split('/\s+/u', $name)[0] ?? 'Customer';
        $prefix = Str::substr($firstWord, 0, min(3, max(1, Str::length($firstWord))));
        return $prefix.'***';
    }

    private function orderPrice(Order $order): string
    {
        $exponent = max(0, (int) $order->currency_exponent);
        $scale = 10 ** $exponent;
        return strtoupper((string) $order->currency).' '.number_format(((int) $order->total_minor) / $scale, $exponent, '.', ',');
    }

    private function packagePrice(Package $package): string
    {
        $scale = 10 ** (int) $package->currency_exponent;
        $amount = number_format(((int) $package->price_minor) / $scale, (int) $package->currency_exponent);
        return $amount.' '.$package->currency;
    }

    private function promotionMoney(Promotion $promotion, int $minor): string
    {
        $scale = 10 ** max(0, (int) $promotion->currency_exponent);
        return strtoupper((string) $promotion->currency).' '.number_format($minor / $scale, max(0, (int) $promotion->currency_exponent), '.', ',');
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds % 86400 === 0) return (int) ($seconds / 86400).' day(s)';
        if ($seconds % 3600 === 0) return (int) ($seconds / 3600).' hour(s)';
        return number_format($seconds).' seconds';
    }
}
