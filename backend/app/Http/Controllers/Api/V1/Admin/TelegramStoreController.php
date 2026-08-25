<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TelegramAccount;
use App\Models\TelegramAnnouncement;
use App\Services\TelegramAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramStoreController extends Controller
{
    public function show(): JsonResponse
    {
        $recent = TelegramAnnouncement::query()
            ->with(['package:id,name,slug', 'modelAlias:id,public_alias,display_name'])
            ->latest()
            ->limit(20)
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

        return response()->json(['data' => [
            'configured' => trim((string) config('services.telegram.bot_token')) !== '' && trim((string) config('services.telegram.webhook_secret')) !== '',
            'active_accounts' => TelegramAccount::query()->whereNull('revoked_at')->count(),
            'announcement_subscribers' => TelegramAccount::query()->whereNull('revoked_at')->where('announcements_enabled', true)->count(),
            'queued_announcements' => TelegramAnnouncement::query()->whereIn('status', ['QUEUED', 'SENDING'])->count(),
            'recent_announcements' => $recent,
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
}
