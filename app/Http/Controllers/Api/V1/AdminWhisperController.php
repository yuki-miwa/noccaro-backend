<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MapWhisper;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhisperController extends ApiController
{
    public function remove(
        Request $request,
        MapWhisper $whisper,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): JsonResponse {
        $actor = $guard->actorForWhisper($request->user(), $whisper);
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $whisper->loadMissing(['space', 'membership']);
        $whisper->forceFill([
            'status' => 'removed_by_owner',
            'removed_at' => now(),
            'removed_by_membership_id' => $actor->id,
        ])->save();

        $logger->log($actor, $whisper->membership, 'remove_whisper', $payload['reason'] ?? null, null, null, [
            'whisperId' => $whisper->public_id,
        ]);

        return $this->ok([
            'whisper' => ApiResource::whisper($whisper->fresh(['space', 'membership'])),
        ]);
    }
}
