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
        $payload = $request->validate([
            'currentLat' => ['required', 'numeric', 'between:-90,90'],
            'currentLng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $state = $liveThreads->stateForSpace($space, $membership, (float) $payload['currentLat'], (float) $payload['currentLng']);

        if (! $state['permissions']['canComment']) {
            $reasonCode = $state['eligibility']['reasonCode'] ?? 'LIVE_CHAT_UNAVAILABLE';
            if ($reasonCode === 'LIVE_THREAD_OUT_OF_AREA') {
                throw new ApiException(
                    'LIVE_THREAD_OUT_OF_AREA',
                    '配信エリア外のためライブチャットに参加できません。',
                    409,
                    [
                        'distanceMeters' => $state['eligibility']['distanceMeters'],
                        'allowedRadiusM' => $state['eligibility']['allowedRadiusM'],
                    ],
                );
            }

            throw new ApiException(
                $reasonCode,
                match ($reasonCode) {
                    'LIVE_THREAD_WINDOW_NOT_OPEN' => 'ライブスレッド開始前のためチャットに参加できません。',
                    'LIVE_THREAD_WINDOW_EXPIRED' => 'ライブスレッド終了後のためチャットに参加できません。',
                    'LIVE_THREAD_NOT_ACTIVE' => 'ライブスレッドが開始されていません。',
                    default => 'ライブチャットは現在利用できません。',
                },
                in_array($reasonCode, ['FORBIDDEN'], true) ? 403 : 409,
            );
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
