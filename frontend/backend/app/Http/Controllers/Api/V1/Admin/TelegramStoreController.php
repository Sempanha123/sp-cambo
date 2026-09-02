<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TelegramAccount;
use App\Models\TelegramAlertChannel;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramPurchaseAlert;
use App\Services\AuditService;
use App\Services\TelegramAnnouncementService;
use App\Services\TelegramBotClient;
use App\Services\TelegramNotificationRouter;
use App\Services\TelegramPurchaseAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelegramStoreController extends Controller
{
    public function show(TelegramNotificationRouter $notifications): JsonResponse
    {
        $recent = TelegramAnnouncement::query()
            ->with(['package:id,name,slug', 'modelAlias:id,public_alias,display_name'])
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (TelegramAnnouncement $row): array => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title,
                'body' => $row->body,
                'status' => $row->status,
                'recipient_count' => (int) $row->recipient_count,
                'sent_count' => (int) $row->sent_count,
                'failed_count' => (int) $row->failed_count,
                'package' => $row->package ? ['id' => $row->package->id, 'name' => $row->package->name, 'slug' => $row->package->slug] : null,
                'model' => $row->modelAlias ? ['id' => $row->modelAlias->id, 'public_alias' => $row->modelAlias->public_alias, 'display_name' => $row->modelAlias->display_name] : null,
                'created_at' => $row->created_at?->toAtomString(),
                'finished_at' => $row->finished_at?->toAtomString(),
            ])->values();

        $storefrontToken = trim((string) config('services.telegram.storefront_bot_token'));
        $storefrontWebhook = trim((string) config('services.telegram.storefront_webhook_secret'));

        return response()->json(['data' => [
            'configured' => $storefrontToken !== '' && $storefrontWebhook !== '',
            'storefront_bot_configured' => $storefrontToken !== '' && $storefrontWebhook !== '',
            'storefront_bot_username' => trim((string) config('services.telegram.storefront_bot_username')) ?: null,
            'website_telegram_silent' => true,
            'purchase_activity_enabled' => (bool) config('services.telegram.purchase_activity_enabled', true),
            'active_accounts' => TelegramAccount::query()->whereNull('revoked_at')->count(),
            'announcement_subscribers' => TelegramAccount::query()->whereNull('revoked_at')->where('announcements_enabled', true)->count(),
            'queued_announcements' => TelegramAnnouncement::query()->whereIn('status', ['QUEUED', 'SENDING'])->count(),
            'sellable_package_count' => Package::query()->published()->where('auto_creates_api_key', true)->count(),
            'limited_stock_packages' => Package::query()->whereNotNull('stock_quantity')->count(),
            'sold_out_packages' => Package::query()->where('stock_quantity', 0)->count(),
            'recent_announcements' => $recent,
            'notification_settings' => $notifications->snapshot(),
            'alert_channels' => TelegramAlertChannel::query()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (TelegramAlertChannel $channel): array => [
                    'id' => (string) $channel->id,
                    'name' => $channel->name,
                    'chat_id' => $channel->chat_id,
                    'enabled' => (bool) $channel->enabled,
                    'created_at' => $channel->created_at?->toAtomString(),
                    'updated_at' => $channel->updated_at?->toAtomString(),
                ])->values(),

            // Compatibility for older cached frontend builds.
            'recent_purchase_messages' => [],
            'pending_purchase_messages' => 0,
            'failed_purchase_messages' => 0,
            'pending_website_alerts' => 0,
            'failed_website_alerts' => 0,
            'website_alert_bot_configured' => false,
            'website_alert_uses_storefront_fallback' => false,
            'website_alert_targets' => 0,
            'customer_website_telegram_alerts_enabled' => false,
            'purchase_feed_enabled' => false,
            'purchase_feed_subscribers_enabled' => false,
            'purchase_feed_targets' => 0,
        ]]);
    }

    public function updateNotificationSettings(
        Request $request,
        TelegramNotificationRouter $notifications,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'event_routes' => ['required', 'array'],
            'event_routes.*' => ['required', Rule::in([
                TelegramNotificationRouter::MODE_OFF,
                TelegramNotificationRouter::MODE_BOT_ONLY,
                TelegramNotificationRouter::MODE_CHANNELS_ONLY,
                TelegramNotificationRouter::MODE_BOTH,
            ])],
            'qr_countdown_enabled' => ['required', 'boolean'],
            'qr_countdown_interval_seconds' => ['required', 'integer', 'between:10,60'],
        ]);

        $before = $notifications->snapshot();
        $notifications->update($input, $request->user());
        $after = $notifications->snapshot();

        $audit->record(
            $request->user(),
            'telegram.notification_settings.updated',
            'telegram_notification_setting',
            '1',
            'Updated Telegram notification routing and KHQR countdown settings.',
            ['before' => $before, 'after' => $after],
        );

        return response()->json(['data' => [
            'settings' => $after,
            'message' => 'Telegram notification settings saved.',
        ]]);
    }

    public function storeChannel(Request $request, AuditService $audit): JsonResponse
    {
        $input = $this->validateChannel($request);

        $channel = TelegramAlertChannel::query()->create($input);

        $audit->record(
            $request->user(),
            'telegram.alert_channel.created',
            'telegram_alert_channel',
            $channel->id,
            'Added a Telegram alert channel.',
            ['name' => $channel->name, 'chat_id' => $channel->chat_id, 'enabled' => $channel->enabled],
        );

        return response()->json(['data' => [
            'channel' => $this->channelResource($channel),
            'message' => 'Alert channel added.',
        ]], 201);
    }

    public function updateChannel(
        Request $request,
        TelegramAlertChannel $channel,
        AuditService $audit,
    ): JsonResponse {
        $before = $this->channelResource($channel);
        $input = $this->validateChannel($request, $channel);
        $channel->update($input);

        $audit->record(
            $request->user(),
            'telegram.alert_channel.updated',
            'telegram_alert_channel',
            $channel->id,
            'Updated a Telegram alert channel.',
            ['before' => $before, 'after' => $this->channelResource($channel->fresh())],
        );

        return response()->json(['data' => [
            'channel' => $this->channelResource($channel->fresh()),
            'message' => 'Alert channel updated.',
        ]]);
    }

    public function destroyChannel(
        Request $request,
        TelegramAlertChannel $channel,
        AuditService $audit,
    ): JsonResponse {
        $snapshot = $this->channelResource($channel);

        $audit->record(
            $request->user(),
            'telegram.alert_channel.deleted',
            'telegram_alert_channel',
            $channel->id,
            'Removed a Telegram alert channel.',
            ['channel' => $snapshot],
        );

        $channel->delete();

        return response()->json(['data' => [
            'success' => true,
            'message' => 'Alert channel removed.',
        ]]);
    }

    public function testChannel(
        TelegramAlertChannel $channel,
        TelegramBotClient $bot,
    ): JsonResponse {
        $bot->sendMessage(
            (string) $channel->chat_id,
            "✅ SP CAMBO TELEGRAM TEST\n\nThis channel is connected and can receive enabled SP Cambo alerts.",
        );

        return response()->json(['data' => [
            'success' => true,
            'message' => 'Test message sent to '.$channel->name.'.',
        ]]);
    }

    public function broadcast(
        Request $request,
        TelegramAnnouncementService $announcements,
        TelegramNotificationRouter $notifications,
    ): JsonResponse {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1800'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'target_mode' => ['sometimes', Rule::in([
                TelegramNotificationRouter::MODE_BOT_ONLY,
                TelegramNotificationRouter::MODE_CHANNELS_ONLY,
                TelegramNotificationRouter::MODE_BOTH,
            ])],
        ]);

        $package = isset($input['package_id']) ? Package::query()->findOrFail((int) $input['package_id']) : null;
        $mode = (string) ($input['target_mode'] ?? TelegramNotificationRouter::MODE_BOT_ONLY);
        $announcement = null;
        $channelCount = 0;

        if (in_array($mode, [TelegramNotificationRouter::MODE_BOT_ONLY, TelegramNotificationRouter::MODE_BOTH], true)) {
            $announcement = $announcements->manual(trim($input['title']), trim($input['body']), $package);
        }

        if (in_array($mode, [TelegramNotificationRouter::MODE_CHANNELS_ONLY, TelegramNotificationRouter::MODE_BOTH], true)) {
            $channelCount = $notifications->sendManualToChannels(trim($input['title']), trim($input['body']));
        }

        return response()->json(['data' => [
            'id' => $announcement?->id,
            'status' => $announcement?->status ?? 'CHANNELS_QUEUED',
            'channel_count' => $channelCount,
            'message' => 'Telegram update queued for the selected destinations.',
        ]], 201);
    }

    public function retryFailed(
        Request $request,
        TelegramAnnouncement $announcement,
        TelegramAnnouncementService $announcements,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $count = $announcements->retryFailed((string) $announcement->id);

        $audit->record(
            $request->user(),
            'telegram_announcement.failed_requeued',
            'telegram_announcement',
            $announcement->id,
            trim((string) $input['reason']),
            ['requeued' => $count],
        );

        return response()->json(['data' => [
            'id' => $announcement->id,
            'requeued' => $count,
            'message' => $count > 0 ? 'Failed recipients were requeued safely.' : 'No failed recipients required retry.',
        ]]);
    }

    public function retryPurchaseAlert(
        Request $request,
        TelegramPurchaseAlert $alert,
        TelegramPurchaseAlertService $alerts,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $requeued = $alerts->retry((string) $alert->id);

        $audit->record(
            $request->user(),
            'telegram_purchase_message.retry',
            'telegram_purchase_alert',
            $alert->id,
            trim((string) $input['reason']),
            ['requeued' => $requeued],
        );

        return response()->json(['data' => [
            'id' => $alert->id,
            'requeued' => $requeued,
            'message' => $requeued ? 'Telegram purchase message was requeued safely.' : 'Only failed Telegram purchase/alert messages can be requeued.',
        ]]);
    }

    /** @return array<string,mixed> */
    private function validateChannel(Request $request, ?TelegramAlertChannel $channel = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'chat_id' => [
                'required',
                'string',
                'max:100',
                'regex:/^(?:-?\d{5,30}|@[A-Za-z0-9_]{5,32})$/',
                Rule::unique('telegram_alert_channels', 'chat_id')->ignore($channel),
            ],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function channelResource(TelegramAlertChannel $channel): array
    {
        return [
            'id' => (string) $channel->id,
            'name' => $channel->name,
            'chat_id' => $channel->chat_id,
            'enabled' => (bool) $channel->enabled,
            'created_at' => $channel->created_at?->toAtomString(),
            'updated_at' => $channel->updated_at?->toAtomString(),
        ];
    }
}
