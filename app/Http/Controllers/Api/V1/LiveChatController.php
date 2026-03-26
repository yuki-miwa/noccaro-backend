<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Live\LiveChatTokenIssuer;
use App\Exceptions\ApiException;
use App\Models\Space;
use App\Support\Api\ApiResource;
use App\Support\Live\LiveThreadService;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChatController extends ApiController
{
    public function issueToken(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        LiveThreadService $liveThreads,
        LiveChatTokenIssuer $tokens,
    ): JsonResponse {
        $membership = $guard->requireActiveMembership($request->user(), $space);
        $thread = $liveThreads->activeThreadForSpace($space);

        if (! $thread) {
            throw new ApiException('LIVE_THREAD_NOT_ACTIVE', 'ライブスレッドが開始されていません。', 409);
        }

        $token = $tokens->issue(
            userId: 'member-'.$membership->public_id,
            attributes: [
                'spaceId' => $space->public_id,
                'membershipId' => $membership->public_id,
                'role' => $membership->role,
            ],
        );

        return $this->ok([
            'chat' => array_merge(
                ApiResource::liveChatToken($token),
                $liveThreads->chatPolicy(),
            ),
        ]);
    }
}
