<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'actor_user_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rows = AuditLog::query()
            ->with('actor:id,name,email')
            ->when(isset($input['action']), fn ($query) => $query->where('action', $input['action']))
            ->when(isset($input['subject_type']), fn ($query) => $query->where('subject_type', $input['subject_type']))
            ->when(isset($input['actor_user_id']), fn ($query) => $query->where('actor_user_id', $input['actor_user_id']))
            ->latest('id')
            ->limit((int) ($input['limit'] ?? 100))
            ->get()
            ->map(fn (AuditLog $row): array => [
                'id' => (string) $row->id,
                'action' => $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'reason' => $row->reason,
                'metadata' => $row->metadata,
                'actor' => $row->actor ? [
                    'id' => (string) $row->actor->id,
                    'name' => $row->actor->name,
                    'email' => $row->actor->email,
                ] : null,
                'created_at' => $row->created_at?->toAtomString(),
            ])->values();

        return response()->json(['data' => $rows]);
    }
}
