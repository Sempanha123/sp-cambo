<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlaygroundChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class PlaygroundChatController extends Controller
{
    private const RETENTION_DAYS = 30;
    private const MAX_CHATS_PER_USER = 30;
    private const MAX_STORED_MESSAGES = 60;
    private const MAX_STORED_MESSAGE_CHARS = 120000;

    public function index(Request $request): JsonResponse
    {
        try {
            $userId = (int) $request->user()->id;
            $this->prune($userId);

            $limit = min(self::MAX_CHATS_PER_USER, max(1, (int) $request->integer('limit', self::MAX_CHATS_PER_USER)));
            $rows = PlaygroundChat::query()
                ->where('user_id', $userId)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (PlaygroundChat $chat) => $this->summary($chat))
                ->values();

            return $this->noStore(response()->json([
                'data' => $rows,
                'meta' => [
                    'retention_days' => self::RETENTION_DAYS,
                    'max_chats' => self::MAX_CHATS_PER_USER,
                    'saved_chats' => $rows->count(),
                ],
            ]));
        } catch (Throwable $e) {
            return $this->historyFailure($e, 'list', (int) ($request->user()?->id ?? 0));
        }
    }

    /**
     * Idempotent browser autosave. The browser owns a random client_key for the
     * open conversation, so retries/hot reloads cannot accidentally create
     * duplicate history rows or lose the current chat id.
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            $input = $this->validatePayload($request, true, true);
            $userId = (int) $request->user()->id;
            $clientKey = trim((string) $input['client_key']);

            $chat = DB::transaction(function () use ($userId, $clientKey, $input): PlaygroundChat {
                $this->prune($userId);
                $messages = $this->normalizeMessages($input['messages'] ?? []);

                $chat = PlaygroundChat::query()->firstOrNew([
                    'user_id' => $userId,
                    'client_key' => $clientKey,
                ]);

                $chat->forceFill([
                    'title' => $this->title($input['title'] ?? $chat->title, $messages),
                    'model_alias' => $input['model_alias'] ?? null,
                    'system_prompt' => $input['system_prompt'] ?? null,
                    'messages' => $messages,
                    'message_count' => count($messages),
                    'last_message_at' => now(),
                    'expires_at' => now()->addDays(self::RETENTION_DAYS),
                ])->save();

                $this->enforceCap($userId, (int) $chat->id);
                return $chat->fresh();
            });

            return $this->noStore(response()->json([
                'data' => $this->resource($chat),
                'meta' => [
                    'saved' => true,
                    'retention_days' => self::RETENTION_DAYS,
                    'max_chats' => self::MAX_CHATS_PER_USER,
                ],
            ], $chat->wasRecentlyCreated ? 201 : 200));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->historyFailure($e, 'sync', (int) ($request->user()?->id ?? 0));
        }
    }

    /** Compatibility endpoint for older Fix27/Fix28 browser bundles. */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validatePayload($request);
        $userId = (int) $request->user()->id;

        $chat = DB::transaction(function () use ($userId, $input): PlaygroundChat {
            $this->prune($userId);
            $messages = $this->normalizeMessages($input['messages'] ?? []);
            $chat = PlaygroundChat::query()->create([
                'user_id' => $userId,
                'client_key' => $input['client_key'] ?? (string) Str::uuid(),
                'title' => $this->title($input['title'] ?? null, $messages),
                'model_alias' => $input['model_alias'] ?? null,
                'system_prompt' => $input['system_prompt'] ?? null,
                'messages' => $messages,
                'message_count' => count($messages),
                'last_message_at' => now(),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ]);

            $this->enforceCap($userId, (int) $chat->id);
            return $chat;
        });

        return $this->noStore(response()->json(['data' => $this->resource($chat)], 201));
    }

    public function show(Request $request, int $chat): JsonResponse
    {
        $row = $this->owned($request, $chat);
        if ($row->expires_at?->isPast()) {
            $row->delete();
            return $this->noStore(response()->json(['message' => 'This Playground chat has expired.', 'code' => 'playground_chat_expired'], 404));
        }

        if (! $row->client_key) {
            $row->forceFill(['client_key' => (string) Str::uuid()])->save();
            $row->refresh();
        }

        return $this->noStore(response()->json(['data' => $this->resource($row)]));
    }

    public function update(Request $request, int $chat): JsonResponse
    {
        $input = $this->validatePayload($request, false);
        $row = $this->owned($request, $chat);
        $messages = array_key_exists('messages', $input)
            ? $this->normalizeMessages($input['messages'] ?? [])
            : (array) ($row->messages ?? []);

        $row->forceFill([
            'client_key' => array_key_exists('client_key', $input) ? ($input['client_key'] ?: $row->client_key) : $row->client_key,
            'title' => $this->title($input['title'] ?? $row->title, $messages),
            'model_alias' => array_key_exists('model_alias', $input) ? ($input['model_alias'] ?: null) : $row->model_alias,
            'system_prompt' => array_key_exists('system_prompt', $input) ? ($input['system_prompt'] ?: null) : $row->system_prompt,
            'messages' => $messages,
            'message_count' => count($messages),
            'last_message_at' => now(),
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ])->save();

        return $this->noStore(response()->json(['data' => $this->resource($row->fresh())]));
    }

    public function destroy(Request $request, int $chat): JsonResponse
    {
        $row = $this->owned($request, $chat);
        $row->delete();

        return $this->noStore(response()->json(['data' => ['deleted' => true, 'id' => $chat]]));
    }

    public function clear(Request $request): JsonResponse
    {
        $deleted = PlaygroundChat::query()->where('user_id', $request->user()->id)->delete();
        return $this->noStore(response()->json(['data' => ['deleted' => $deleted]]));
    }

    private function validatePayload(Request $request, bool $creating = true, bool $sync = false): array
    {
        $rules = [
            'client_key' => [$sync ? 'required' : ($creating ? 'nullable' : 'sometimes'), 'nullable', 'string', 'min:8', 'max:64'],
            'title' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:120'],
            'model_alias' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:150'],
            'system_prompt' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:12000'],
            'messages' => [$creating ? 'required' : 'sometimes', 'array', 'max:'.self::MAX_STORED_MESSAGES],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            // Do not reject a useful chat because an AI produced a large code
            // block. normalizeMessages() truncates only the persisted copy.
            'messages.*.content' => ['required', 'string'],
        ];

        return $request->validate($rules);
    }

    private function normalizeMessages(array $messages): array
    {
        $messages = array_slice($messages, -self::MAX_STORED_MESSAGES);
        $normalized = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true) || trim($content) === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_STORED_MESSAGE_CHARS) {
                $content = mb_substr($content, 0, self::MAX_STORED_MESSAGE_CHARS)."\n\n[Saved history truncated for storage]";
            }

            $normalized[] = ['role' => $role, 'content' => $content];
        }

        return array_values($normalized);
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
        // MySQL does not accept OFFSET without LIMIT. Laravel's ->skip() by
        // itself can therefore compile to invalid SQL ("... offset 29").
        // Keep the current chat plus the newest 29 other chats, then delete
        // everything outside that allow-list. This is portable across MySQL,
        // MariaDB and SQLite and preserves the 30-chat account cap.
        $keepOtherIds = PlaygroundChat::query()
            ->where('user_id', $userId)
            ->where('id', '<>', $keepId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(max(0, self::MAX_CHATS_PER_USER - 1))
            ->pluck('id')
            ->all();

        $overflow = PlaygroundChat::query()
            ->where('user_id', $userId)
            ->where('id', '<>', $keepId);

        if ($keepOtherIds !== []) {
            $overflow->whereNotIn('id', $keepOtherIds);
        }

        $overflow->delete();
    }

    private function summary(PlaygroundChat $chat): array
    {
        return [
            'id' => $chat->id,
            'client_key' => $chat->client_key,
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

    private function historyFailure(Throwable $e, string $action, int $userId): JsonResponse
    {
        $ref = 'hist_'.Str::lower(Str::random(10));
        Log::error('Playground chat history storage failure', [
            'ref' => $ref,
            'action' => $action,
            'user_id' => $userId ?: null,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return $this->noStore(response()->json([
            'message' => "Chat history storage is temporarily unavailable. Run the Playground history check and restart SP Cambo. Reference: {$ref}",
            'code' => 'playground_history_unavailable',
            'request_ref' => $ref,
        ], 503));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
