<?php

namespace App\Services;

use App\Models\ModelAlias;
use App\Models\Package;
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
                'Price: '.$this->packagePrice($package),
                'Includes: '.number_format((int) $package->advertised_units).' '.$package->unit_label,
                'Validity: '.$duration,
                $models !== '' ? 'Models: '.$models : null,
            ])),
            'package_id' => $package->id,
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
                'Alias: '.$alias->public_alias,
                $provider ? 'Provider: '.$provider : null,
                $alias->description,
                'Open the store to see packages that include this model.',
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

    /** @param array<string,mixed> $attributes */
    private function enqueue(array $attributes): TelegramAnnouncement
    {
        return TelegramAnnouncement::query()->firstOrCreate(
            ['event_key' => (string) $attributes['event_key']],
            [
                'kind' => (string) $attributes['kind'],
                'title' => (string) $attributes['title'],
                'body' => (string) $attributes['body'],
                'package_id' => $attributes['package_id'] ?? null,
                'model_alias_id' => $attributes['model_alias_id'] ?? null,
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
            if ($remaining <= 0) break;
            $announcements++;
            $this->prepareDeliveries($announcement);

            $deliveries = TelegramAnnouncementDelivery::query()
                ->with('account')
                ->where('telegram_announcement_id', $announcement->id)
                ->where('status', 'PENDING')
                ->orderBy('id')
                ->limit($remaining)
                ->get();

            foreach ($deliveries as $delivery) {
                $attempted++;
                $remaining--;
                $account = $delivery->account;

                if (! $account || $account->revoked_at !== null || ! $account->announcements_enabled) {
                    $delivery->forceFill([
                        'status' => 'SKIPPED',
                        'attempted_at' => now(),
                        'last_error' => null,
                    ])->save();
                    continue;
                }

                try {
                    $message = $this->message($announcement, $account);
                    $this->bot->sendMessage($account->chat_id, $message['text'], $message['reply_markup']);
                    $delivery->forceFill([
                        'status' => 'SENT',
                        'attempted_at' => now(),
                        'last_error' => null,
                    ])->save();
                    $sent++;
                } catch (Throwable $e) {
                    $delivery->forceFill([
                        'status' => 'FAILED',
                        'attempted_at' => now(),
                        'last_error' => Str::limit($e->getMessage(), 1000),
                    ])->save();
                    report($e);
                    $failed++;
                }
            }

            $this->refreshStatus($announcement);
        }

        return compact('announcements', 'attempted', 'sent', 'failed');
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
            ->selectRaw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count")
            ->first();

        $pending = (int) ($counts?->pending_count ?? 0);
        TelegramAnnouncement::query()->whereKey($announcement->id)->update([
            'sent_count' => (int) ($counts?->sent_count ?? 0),
            'failed_count' => (int) ($counts?->failed_count ?? 0),
            'status' => $pending === 0 ? 'COMPLETED' : 'SENDING',
            'finished_at' => $pending === 0 ? now() : null,
        ]);
    }

    /** @return array{text:string,reply_markup:array<string,mixed>} */
    private function message(TelegramAnnouncement $announcement, TelegramAccount $account): array
    {
        $km = $account->locale === 'km';
        $icon = match ($announcement->kind) {
            'NEW_MODEL' => '🧠',
            'NEW_PACKAGE' => '📦',
            'PACKAGE_UPDATE' => '✨',
            default => '📣',
        };
        $heading = match ($announcement->kind) {
            'NEW_MODEL' => $km ? 'ម៉ូដែលថ្មីនៅ SP Cambo' : 'New model on SP Cambo',
            'NEW_PACKAGE' => $km ? 'កញ្ចប់ថ្មីនៅ SP Cambo' : 'New package on SP Cambo',
            'PACKAGE_UPDATE' => $km ? 'កញ្ចប់បានធ្វើបច្ចុប្បន្នភាព' : 'Package updated',
            default => $announcement->title,
        };

        $keyboard = [];
        if ($announcement->package_id !== null) {
            $keyboard[] = [[
                'text' => $km ? '🛒 ទិញឥឡូវ' : '🛒 Buy now',
                'callback_data' => 'buy:'.$announcement->package_id,
            ]];
        }
        $keyboard[] = [[
            'text' => $km ? '🛍 បើកហាង' : '🛍 Open store',
            'callback_data' => 'store:1',
        ]];
        $keyboard[] = [[
            'text' => $km ? '🔕 បិទព័ត៌មានថ្មី' : '🔕 Mute updates',
            'callback_data' => 'updates:off',
        ]];

        return [
            'text' => $icon.' '.$heading."\n\n".$announcement->body,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    private function packagePrice(Package $package): string
    {
        $scale = 10 ** (int) $package->currency_exponent;
        $amount = number_format(((int) $package->price_minor) / $scale, (int) $package->currency_exponent);
        return $amount.' '.$package->currency;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds % 86400 === 0) return (int) ($seconds / 86400).' day(s)';
        if ($seconds % 3600 === 0) return (int) ($seconds / 3600).' hour(s)';
        return number_format($seconds).' seconds';
    }
}
