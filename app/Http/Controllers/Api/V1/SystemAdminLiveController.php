<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Space;
use App\Support\Api\ApiResource;
use App\Support\Live\LiveThreadService;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAdminLiveController extends ApiController
{
    public function index(
        Request $request,
        SystemAdminGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $guard->actor($request->user());
        $status = $request->string('status')->value() ?: 'active';
        $limit = (int) ($request->integer('limit') ?: 50);
        $items = $liveThreads->activeSummaries($limit, $status);

        return $this->collection(
            $items->map(
                fn (array $item) => ApiResource::systemLiveSummary(
                    $item['space'],
                    $item['scheduledThread'],
                    $item['liveThread'],
                    $item['liveStream'],
                )
            )->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function forceCloseThread(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $result = $liveThreads->closeThread($space, systemAdmin: $actor, reason: 'force_closed');

        $logger->log(
            $actor,
            'live_thread_force_closed',
            'space',
            $space->public_id,
            sprintf('%s のライブスレッドを強制終了しました。', $space->name),
            ['spaceId' => $space->public_id],
        );

        return $this->ok(
            ApiResource::systemLiveSummary(
                $space->fresh('memberships.user'),
                $liveThreads->currentScheduleForSpace($space),
                $result['liveThread'],
                $result['liveStream'],
            )
        );
    }

    public function forceEndStream(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $stream = $liveThreads->endStream($space, systemAdmin: $actor, reason: 'force_ended');
        $thread = $liveThreads->activeThreadForSpace($space);

        $logger->log(
            $actor,
            'live_stream_force_ended',
            'space',
            $space->public_id,
            sprintf('%s のライブ配信を強制終了しました。', $space->name),
            ['spaceId' => $space->public_id],
        );

        return $this->ok(
            ApiResource::systemLiveSummary(
                $space->fresh('memberships.user'),
                $liveThreads->currentScheduleForSpace($space),
                $thread,
                $stream,
            )
        );
    }
}
