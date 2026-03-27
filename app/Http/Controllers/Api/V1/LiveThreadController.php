<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Space;
use App\Support\Api\ApiResource;
use App\Support\Live\LiveThreadService;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveThreadController extends ApiController
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

        return $this->ok($this->statePayload($space, $state, $liveThreads));
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
        $liveThreads->startThread($space, $membership, (float) $payload['currentLat'], (float) $payload['currentLng']);
        $state = $liveThreads->stateForSpace($space, $membership, (float) $payload['currentLat'], (float) $payload['currentLng']);

        return $this->ok($this->statePayload($space, $state, $liveThreads));
    }

    public function close(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $liveThreads->closeThread($space, $membership, reason: 'closed');
        $state = $liveThreads->stateForSpace($space, $membership);

        return $this->ok($this->statePayload($space, $state, $liveThreads));
    }

    private function statePayload(
        Space $space,
        array $state,
        LiveThreadService $liveThreads,
    ): array {
        return [
            'scheduledThread' => ApiResource::liveThreadSchedule($state['scheduledThread']),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
            'spaceId' => $space->public_id,
        ];
    }
}
