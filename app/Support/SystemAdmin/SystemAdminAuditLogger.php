<?php

namespace App\Support\SystemAdmin;

use App\Models\SystemAdmin;
use App\Models\SystemAdminAuditLog;

class SystemAdminAuditLogger
{
    public function log(
        SystemAdmin $actor,
        string $action,
        string $entityType,
        string $entityPublicId,
        string $message,
        array $metadata = [],
    ): SystemAdminAuditLog {
        return SystemAdminAuditLog::query()->create([
            'system_admin_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_public_id' => $entityPublicId,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
