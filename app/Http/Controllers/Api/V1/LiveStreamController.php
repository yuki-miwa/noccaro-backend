<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Space;
use App\Support\Api\ApiResource;
use App\Support\Live\LiveThreadService;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveStreamController extends ApiController
{
    public function show(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActiveMembership($request->user(), $space);
        $state = $liveThreads->currentStateForSpace($space);

        return $this->ok([
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions(
                $liveThreads->permissionsForMembership($membership, $state['liveThread'], $state['liveStream'])
            ),
            'spaceId' => $space->public_id,
        ]);
    }

    public function start(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $stream = $liveThreads->startStream($space, $membership);
        $thread = $liveThreads->activeThreadForSpace($space);

        return $this->ok([
            'liveThread' => ApiResource::liveThread($thread),
            'liveStream' => ApiResource::liveStream($stream, includeBroadcastFields: true),
            'broadcast' => [
                'streamKey' => (string) config('live.stream_key'),
                'ingestEndpoint' => (string) config('live.ingest_endpoint'),
                'channelArn' => (string) config('live.channel_arn'),
            ],
            'permissions' => ApiResource::livePermissions(
                $liveThreads->permissionsForMembership($membership, $thread, $stream)
            ),
        ]);
    }

    public function end(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $stream = $liveThreads->endStream($space, $membership, reason: 'ended');
        $thread = $liveThreads->activeThreadForSpace($space);

        return $this->ok([
            'liveThread' => ApiResource::liveThread($thread),
            'liveStream' => ApiResource::liveStream($stream),
            'permissions' => ApiResource::livePermissions(
                $liveThreads->permissionsForMembership($membership, $thread, null)
            ),
        ]);
    }
}
