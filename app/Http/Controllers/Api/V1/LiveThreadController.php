<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
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
        $guard->requireActivePrimaryOwnerMembership($request->user(), $space);

        throw new ApiException('FORBIDDEN', 'ライブスレッドは予約時刻で自動開始されます。', 403);
    }

    public function close(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $guard->requireActivePrimaryOwnerMembership($request->user(), $space);

        throw new ApiException('FORBIDDEN', 'ライブスレッドは終了時刻で自動終了されます。', 403);
    }

    private function statePayload(
        Space $space,
        array $state,
        LiveThreadService $liveThreads,
    ): array {
        return [
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
        ];
    }
}
