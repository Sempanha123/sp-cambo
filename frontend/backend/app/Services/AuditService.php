<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditService
{
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization', 'cookie', 'credential', 'password', 'secret', 'token',
        'api_key', 'apikey', 'access_key', 'private_key', 'qr_payload',
    ];

    public function record(User $actor, string $action, string $subjectType, string|int $subjectId, ?string $reason = null, array $metadata = []): AuditLog
    {
        return $this->create($actor->id, $action, $subjectType, $subjectId, $reason, $metadata);
    }

    public function recordSystem(string $action, string $subjectType, string|int $subjectId, string $reason, array $metadata = []): AuditLog
    {
        return $this->create(null, $action, $subjectType, $subjectId, $reason, $metadata);
    }

    private function create(?int $actorUserId, string $action, string $subjectType, string|int $subjectId, ?string $reason, array $metadata): AuditLog
    {
        return AuditLog::query()->create([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'reason' => $this->scrubString($reason),
            'metadata' => $metadata === [] ? null : $this->sanitizeMetadata($metadata),
        ]);
    }

    private function sanitizeMetadata(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->sanitizeMetadata($childValue, is_string($childKey) ? $childKey : null);
            }

            return $safe;
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function scrubString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Do not let accidentally pasted SP Cambo/OpenAI-like bearer keys become
        // permanent audit evidence. The exact secret is never useful in an audit.
        return preg_replace('/\b(?:sk-[A-Za-z0-9_-]{12,}|SPC-LINK-[A-Za-z0-9_-]{6,})\b/i', '[redacted]', $value) ?? '[redacted]';
    }
}
