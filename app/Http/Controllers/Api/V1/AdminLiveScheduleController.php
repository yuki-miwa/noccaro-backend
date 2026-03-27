<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Space;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use App\Support\Live\LiveThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLiveScheduleController extends ApiController
{
    public function show(
        Request $request,
        Space $space,
        SpaceAdminGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $actor = $guard->actorForSpace($request->user(), $space);
        $state = $liveThreads->stateForSpace($space, $actor);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($state['scheduledThread']),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
            'spaceId' => $space->public_id,
        ]);
    }

    public function update(
        Request $request,
        Space $space,
        SpaceAdminGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $actor = $guard->actorForSpace($request->user(), $space);
        $payload = $request->validate([
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'areaCenterLat' => ['required', 'numeric', 'between:-90,90'],
            'areaCenterLng' => ['required', 'numeric', 'between:-180,180'],
            'areaRadiusM' => ['required', 'integer', 'min:10', 'max:5000'],
        ]);

        $schedule = $liveThreads->upsertSchedule($space, $actor, [
            'starts_at' => $payload['startsAt'],
            'ends_at' => $payload['endsAt'],
            'area_center_lat' => $payload['areaCenterLat'],
            'area_center_lng' => $payload['areaCenterLng'],
            'area_radius_m' => $payload['areaRadiusM'],
        ]);
        $state = $liveThreads->stateForSpace($space, $actor);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($schedule),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
            'spaceId' => $space->public_id,
        ]);
    }

    public function destroy(
        Request $request,
        Space $space,
        SpaceAdminGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $actor = $guard->actorForSpace($request->user(), $space);
        $schedule = $liveThreads->cancelSchedule($space, $actor);
        $state = $liveThreads->stateForSpace($space, $actor);

        return $this->ok([
            'scheduledThread' => ApiResource::liveThreadSchedule($schedule),
            'liveThread' => ApiResource::liveThread($state['liveThread']),
            'liveStream' => ApiResource::liveStream($state['liveStream']),
            'permissions' => ApiResource::livePermissions($state['permissions']),
            'eligibility' => ApiResource::liveEligibility($state['eligibility']),
            'chatPolicy' => $liveThreads->chatPolicy(),
            'spaceId' => $space->public_id,
        ]);
    }
}
