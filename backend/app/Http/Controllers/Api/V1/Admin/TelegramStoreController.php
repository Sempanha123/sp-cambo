<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TelegramAccount;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramPurchaseAlert;
use App\Services\AuditService;
use App\Services\TelegramAnnouncementService;
use App\Services\TelegramPurchaseAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramStoreController extends Controller
{
    public function show(): JsonResponse
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
            // Compatibility for a cached Fix17 frontend. No new rows are created.
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

    public function broadcast(Request $request, TelegramAnnouncementService $announcements): JsonResponse
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1800'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
        ]);
        $package = isset($input['package_id']) ? Package::query()->findOrFail((int) $input['package_id']) : null;
        $announcement = $announcements->manual(trim($input['title']), trim($input['body']), $package);

        return response()->json(['data' => [
            'id' => $announcement->id,
            'status' => $announcement->status,
            'message' => 'Announcement queued. The scheduler will deliver it to subscribed Telegram customers.',
        ]], 201);
    }
    public function retryFailed(Request $request, TelegramAnnouncement $announcement, TelegramAnnouncementService $announcements, AuditService $audit): JsonResponse
    {
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

    public function retryPurchaseAlert(Request $request, TelegramPurchaseAlert $alert, TelegramPurchaseAlertService $alerts, AuditService $audit): JsonResponse
    {
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

}
