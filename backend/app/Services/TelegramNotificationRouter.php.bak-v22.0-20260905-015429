<?php

namespace App\Services;

use App\Jobs\SendTelegramAlertChannelMessage;
use App\Models\TelegramAlertChannel;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramNotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramNotificationRouter
{
    public const MODE_OFF = 'OFF';
    public const MODE_BOT_ONLY = 'BOT_ONLY';
    public const MODE_CHANNELS_ONLY = 'CHANNELS_ONLY';
    public const MODE_BOTH = 'BOTH';

    /**
     * BOT means the existing SP Cambo Store Bot subscribers.
     * CHANNELS means every enabled channel configured in Admin → Telegram Store.
     *
     * @var array<string,string>
     */
    public const EVENTS = [
        'package_created' => 'New package',
        'package_updated' => 'Package updated',
        'stock_updated' => 'Stock / restock',
        'model_created' => 'New model',
        'model_updated' => 'Model updated',
        'promotion_changed' => 'Promotion created / updated',
        'purchase_activity' => 'Verified purchase activity',
    ];

    /** @return array<string,string> */
    public function defaultRoutes(): array
    {
        return array_fill_keys(array_keys(self::EVENTS), self::MODE_BOT_ONLY);
    }

    public function setting(): TelegramNotificationSetting
    {
        return TelegramNotificationSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'enabled' => true,
                'event_routes' => $this->defaultRoutes(),
                'qr_countdown_enabled' => true,
                'qr_countdown_interval_seconds' => 15,
            ],
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $setting = $this->setting();

        return [
            'enabled' => (bool) $setting->enabled,
            'event_routes' => $this->normalizedRoutes($setting->event_routes),
            'qr_countdown_enabled' => (bool) $setting->qr_countdown_enabled,
            'qr_countdown_interval_seconds' => $this->countdownInterval($setting),
            'event_definitions' => collect(self::EVENTS)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'route_modes' => [
                ['value' => self::MODE_OFF, 'label' => 'Off'],
                ['value' => self::MODE_BOT_ONLY, 'label' => 'Bot only'],
                ['value' => self::MODE_CHANNELS_ONLY, 'label' => 'Channels only'],
                ['value' => self::MODE_BOTH, 'label' => 'Bot + channels'],
            ],
        ];
    }

    /** @param array<string,mixed> $input */
    public function update(array $input, ?User $actor = null): TelegramNotificationSetting
    {
        $routes = $this->normalizedRoutes($input['event_routes'] ?? []);

        $setting = $this->setting();
        $setting->forceFill([
            'enabled' => (bool) ($input['enabled'] ?? true),
            'event_routes' => $routes,
            'qr_countdown_enabled' => (bool) ($input['qr_countdown_enabled'] ?? true),
            'qr_countdown_interval_seconds' => max(10, min(60, (int) ($input['qr_countdown_interval_seconds'] ?? 15))),
            'updated_by_user_id' => $actor?->getKey(),
        ])->save();

        return $setting->fresh();
    }

    public function modeForEvent(string $event): string
    {
        $setting = $this->setting();

        if (! $setting->enabled) {
            return self::MODE_OFF;
        }

        $routes = $this->normalizedRoutes($setting->event_routes);

        return $routes[$event] ?? self::MODE_OFF;
    }

    public function botEnabled(string $event): bool
    {
        return in_array($this->modeForEvent($event), [self::MODE_BOT_ONLY, self::MODE_BOTH], true);
    }

    public function channelsEnabled(string $event): bool
    {
        return in_array($this->modeForEvent($event), [self::MODE_CHANNELS_ONLY, self::MODE_BOTH], true);
    }

    public function qrCountdownEnabled(): bool
    {
        $setting = $this->setting();

        return (bool) ($setting->enabled && $setting->qr_countdown_enabled);
    }

    public function qrCountdownInterval(): int
    {
        return $this->countdownInterval($this->setting());
    }

    /**
     * Runs immediately after an automatic TelegramAnnouncement is created.
     *
     * The old Store Bot queue remains the BOT path. CHANNELS is separate and
     * never makes an admin/catalog write wait for Telegram network I/O.
     */
    public function routeAnnouncement(TelegramAnnouncement $announcement): void
    {
        $event = $this->eventForKind((string) $announcement->kind);
        if ($event === null) {
            // MANUAL and unknown kinds preserve the old subscriber-only behavior.
            return;
        }

        $mode = $this->modeForEvent($event);

        if (in_array($mode, [self::MODE_CHANNELS_ONLY, self::MODE_BOTH], true)) {
            $this->dispatchToChannels($this->announcementText($announcement));
        }

        if (! in_array($mode, [self::MODE_BOT_ONLY, self::MODE_BOTH], true)) {
            TelegramAnnouncement::query()
                ->whereKey($announcement->getKey())
                ->where('status', 'QUEUED')
                ->update([
                    'status' => 'CANCELLED',
                    'finished_at' => now(),
                ]);
        }
    }

    public function sendManualToChannels(string $title, string $body): int
    {
        return $this->dispatchToChannels(
            "📣 SP CAMBO UPDATE\n\n".trim($title)."\n\n".trim($body)
        );
    }

    public function eventForKind(string $kind): ?string
    {
        return match (strtoupper($kind)) {
            'NEW_PACKAGE' => 'package_created',
            'PACKAGE_UPDATE' => 'package_updated',
            'RESTOCK', 'STOCK_ADDED' => 'stock_updated',
            'NEW_MODEL' => 'model_created',
            'MODEL_UPDATE' => 'model_updated',
            'PROMOTION' => 'promotion_changed',
            'PURCHASE_ACTIVITY' => 'purchase_activity',
            default => null,
        };
    }

    /** @return array<string,string> */
    private function normalizedRoutes(mixed $routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $allowed = [self::MODE_OFF, self::MODE_BOT_ONLY, self::MODE_CHANNELS_ONLY, self::MODE_BOTH];
        $normalized = $this->defaultRoutes();

        foreach (array_keys(self::EVENTS) as $event) {
            $mode = strtoupper(trim((string) ($routes[$event] ?? $normalized[$event])));
            $normalized[$event] = in_array($mode, $allowed, true) ? $mode : self::MODE_BOT_ONLY;
        }

        return $normalized;
    }

    private function countdownInterval(TelegramNotificationSetting $setting): int
    {
        return max(10, min(60, (int) ($setting->qr_countdown_interval_seconds ?: 15)));
    }

    private function dispatchToChannels(string $text): int
    {
        $channels = TelegramAlertChannel::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['id']);

        foreach ($channels as $channel) {
            SendTelegramAlertChannelMessage::dispatch(
                (int) $channel->id,
                mb_substr($text, 0, 4000),
            )->afterCommit();
        }

        return $channels->count();
    }

    private function announcementText(TelegramAnnouncement $announcement): string
    {
        $kind = strtoupper((string) $announcement->kind);
        $icon = match ($kind) {
            'NEW_PACKAGE' => '📦',
            'PACKAGE_UPDATE' => '✨',
            'RESTOCK', 'STOCK_ADDED' => '📥',
            'NEW_MODEL', 'MODEL_UPDATE' => '🧠',
            'PROMOTION' => '🏷',
            'PURCHASE_ACTIVITY' => '🎉',
            default => '🔔',
        };

        if ($kind === 'PURCHASE_ACTIVITY') {
            $meta = is_array($announcement->metadata) ? $announcement->metadata : [];

            return implode("\n", array_filter([
                '🎉 SP CAMBO · VERIFIED ORDER',
                '',
                isset($meta['masked_customer']) ? '👤 '.$meta['masked_customer'] : null,
                isset($meta['package_name']) ? '📦 '.$meta['package_name'] : null,
                isset($meta['price'], $meta['currency']) ? '💵 '.$meta['price'].' '.$meta['currency'] : null,
                isset($meta['quota']) ? '🪙 '.$meta['quota'] : null,
                isset($meta['validity']) ? '⏱ '.$meta['validity'] : null,
                '',
                '✅ Payment and fulfillment verified by SP Cambo.',
            ]));
        }

        return $icon." SP CAMBO · ".strtoupper(str_replace('_', ' ', $kind))
            ."\n\n".trim((string) $announcement->title)
            ."\n\n".trim((string) $announcement->body);
    }
}
