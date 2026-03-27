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
        $currentLat = $request->filled('currentLat') ? (float) $request->input('currentLat') : null;
        $currentLng = $request->filled('currentLng') ? (float) $request->input('currentLng') : null;
        $state = $liveThreads->stateForSpace($space, $membership, $currentLat, $currentLng);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($state['scheduledThread']),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream(
                $state['liveStream'],
                revealPlayback: (bool) ($state['permissions']['canWatch'] ?? false),
            ),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
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
        $payload = $request->validate([
            'currentLat' => ['required', 'numeric', 'between:-90,90'],
            'currentLng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $stream = $liveThreads->startStream($space, $membership, (float) $payload['currentLat'], (float) $payload['currentLng']);
        $state = $liveThreads->stateForSpace($space, $membership, (float) $payload['currentLat'], (float) $payload['currentLng']);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($state['scheduledThread']),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($stream, includeBroadcastFields: true),
            'broadcast' => [
                'streamKey' => (string) config('live.stream_key'),
                'ingestEndpoint' => (string) config('live.ingest_endpoint'),
                'channelArn' => (string) config('live.channel_arn'),
            ],
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
        ]);
    }

    public function end(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $liveThreads->endStream($space, $membership, reason: 'ended');
        $state = $liveThreads->stateForSpace($space, $membership);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($state['scheduledThread']),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
        ]);
    }
}
