<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditService
{
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
        return AuditLog::query()->create(['actor_user_id' => $actorUserId, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => (string) $subjectId, 'reason' => $reason, 'metadata' => $metadata ?: null]);
    }
}
