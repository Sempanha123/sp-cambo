<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlaygroundChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlaygroundChatController extends Controller
{
    private const RETENTION_DAYS = 30;
    private const MAX_CHATS_PER_USER = 30;
    private const MAX_STORED_MESSAGES = 60;

    public function index(Request $request): JsonResponse
    {
        $this->prune((int) $request->user()->id);

        $limit = min(self::MAX_CHATS_PER_USER, max(1, (int) $request->integer('limit', self::MAX_CHATS_PER_USER)));
        $rows = PlaygroundChat::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PlaygroundChat $chat) => $this->summary($chat))
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'retention_days' => self::RETENTION_DAYS,
                'max_chats' => self::MAX_CHATS_PER_USER,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validatePayload($request);
        $userId = (int) $request->user()->id;

        $chat = DB::transaction(function () use ($userId, $input): PlaygroundChat {
            $this->prune($userId);

            $messages = $this->normalizeMessages($input['messages'] ?? []);
            $chat = PlaygroundChat::query()->create([
                'user_id' => $userId,
                'title' => $this->title($input['title'] ?? null, $messages),
                'model_alias' => $input['model_alias'] ?? null,
                'system_prompt' => $input['system_prompt'] ?? null,
                'messages' => $messages,
                'message_count' => count($messages),
                'last_message_at' => now(),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ]);

            $this->enforceCap($userId, $chat->id);
            return $chat;
        });

        return response()->json(['data' => $this->resource($chat)], 201);
    }

    public function show(Request $request, int $chat): JsonResponse
    {
        $row = $this->owned($request, $chat);
        if ($row->expires_at?->isPast()) {
            $row->delete();
            return response()->json(['message' => 'This Playground chat has expired.', 'code' => 'playground_chat_expired'], 404);
        }

        return response()->json(['data' => $this->resource($row)]);
    }

    public function update(Request $request, int $chat): JsonResponse
    {
        $input = $this->validatePayload($request, false);
        $row = $this->owned($request, $chat);
        $messages = array_key_exists('messages', $input)
            ? $this->normalizeMessages($input['messages'] ?? [])
            : (array) ($row->messages ?? []);

        $row->forceFill([
            'title' => $this->title($input['title'] ?? $row->title, $messages),
            'model_alias' => array_key_exists('model_alias', $input) ? ($input['model_alias'] ?: null) : $row->model_alias,
            'system_prompt' => array_key_exists('system_prompt', $input) ? ($input['system_prompt'] ?: null) : $row->system_prompt,
            'messages' => $messages,
            'message_count' => count($messages),
            'last_message_at' => now(),
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ])->save();

        return response()->json(['data' => $this->resource($row->fresh())]);
    }

    public function destroy(Request $request, int $chat): JsonResponse
    {
        $row = $this->owned($request, $chat);
        $row->delete();

        return response()->json(['data' => ['deleted' => true, 'id' => $chat]]);
    }

    public function clear(Request $request): JsonResponse
    {
        $deleted = PlaygroundChat::query()->where('user_id', $request->user()->id)->delete();
        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    private function validatePayload(Request $request, bool $creating = true): array
    {
        $rules = [
            'title' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:120'],
            'model_alias' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:150'],
            'system_prompt' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:12000'],
            'messages' => [$creating ? 'required' : 'sometimes', 'array', 'max:'.self::MAX_STORED_MESSAGES],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:100000'],
        ];

        return $request->validate($rules);
    }

    private function normalizeMessages(array $messages): array
    {
        $messages = array_slice($messages, -self::MAX_STORED_MESSAGES);
        return array_values(array_map(static fn (array $message): array => [
            'role' => (string) ($message['role'] ?? 'user'),
            'content' => (string) ($message['content'] ?? ''),
        ], $messages));
    }

    private function owned(Request $request, int $id): PlaygroundChat
    {
        return PlaygroundChat::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
    }

    private function title(?string $requested, array $messages): string
    {
        $requested = trim((string) $requested);
        if ($requested !== '') {
            return Str::limit(preg_replace('/\s+/u', ' ', $requested) ?: $requested, 120, '');
        }

        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') continue;
            $candidate = trim(preg_replace('/\s+/u', ' ', (string) ($message['content'] ?? '')) ?: '');
            if ($candidate !== '') return Str::limit($candidate, 64, '…');
        }

        return 'New chat';
    }

    private function prune(int $userId): void
    {
        PlaygroundChat::query()
            ->where('user_id', $userId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function enforceCap(int $userId, int $keepId): void
    {
        $ids = PlaygroundChat::query()
            ->where('user_id', $userId)
            ->where('id', '<>', $keepId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->skip(self::MAX_CHATS_PER_USER - 1)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            PlaygroundChat::query()->whereIn('id', $ids)->delete();
        }
    }

    private function summary(PlaygroundChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'model_alias' => $chat->model_alias,
            'message_count' => $chat->message_count,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'expires_at' => $chat->expires_at?->toIso8601String(),
        ];
    }

    private function resource(PlaygroundChat $chat): array
    {
        return [
            ...$this->summary($chat),
            'system_prompt' => $chat->system_prompt,
            'messages' => array_values((array) ($chat->messages ?? [])),
            'created_at' => $chat->created_at?->toIso8601String(),
            'updated_at' => $chat->updated_at?->toIso8601String(),
        ];
    }
}
