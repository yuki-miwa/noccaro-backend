<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SystemAdminAuditLog;
use App\Support\Api\ApiResource;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAdminAuditController extends ApiController
{
    public function index(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $limit = (int) ($request->integer('limit') ?: 100);

        $logs = SystemAdminAuditLog::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->collection(
            $logs->map(fn (SystemAdminAuditLog $log) => ApiResource::systemAuditLog($log))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }
}
