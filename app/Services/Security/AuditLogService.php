<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public static function log(
        string $event,
        ?Model $auditable = null,
        array $metadata = [],
        ?int $workspaceId = null,
        ?int $userId = null
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $workspaceId ?? ($auditable->workspace_id ?? null),
            'user_id' => $userId ?? auth()->id(),
            'event' => $event,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'metadata' => $metadata,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 250),
            'created_at' => now()->utc(),
        ]);
    }
}

