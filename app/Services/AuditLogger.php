<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogger
{
    public static function log(?int $actorId, ?int $tenantId, string $action, array $meta = []): void
    {
        try {
            AuditLog::create([
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'action' => $action,
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            // Deliberately swallow audit failures so admin actions don't break.
        }
    }
}

