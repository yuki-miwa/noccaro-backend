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
        $state = $liveThreads->currentStateForSpace($space);

        return $this->ok($this->statePayload($space, $membership, $state['liveThread'], $state['liveStream'], $liveThreads));
    }

    public function start(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $thread = $liveThreads->startThread($space, $membership);
        $stream = $liveThreads->activeStreamForSpace($space);

        return $this->ok($this->statePayload($space, $membership, $thread, $stream, $liveThreads));
    }

    public function close(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
    ): JsonResponse {
        $membership = $guard->requireActivePrimaryOwnerMembership($request->user(), $space);
        $result = $liveThreads->closeThread($space, $membership, reason: 'closed');

        return $this->ok($this->statePayload(
            $space,
            $membership,
            null,
            null,
            $liveThreads,
            [
                'liveThread' => $result['liveThread'],
                'liveStream' => null,
            ],
        ));
    }

    private function statePayload(
        Space $space,
        $membership,
        $thread,
        $stream,
        LiveThreadService $liveThreads,
        ?array $stateOverride = null,
    ): array {
        $thread = $stateOverride['liveThread'] ?? $thread;
        $stream = $stateOverride['liveStream'] ?? $stream;
        $permissions = $liveThreads->permissionsForMembership($membership, $thread, $stream);

        return [
            'liveThread' => ApiResource::liveThread($thread),
            'liveStream' => ApiResource::liveStream($stream),
            'permissions' => ApiResource::livePermissions($permissions),
            'chatPolicy' => $liveThreads->chatPolicy(),
            'spaceId' => $space->public_id,
        ];
    }
}
