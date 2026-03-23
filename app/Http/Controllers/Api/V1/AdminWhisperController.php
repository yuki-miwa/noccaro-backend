<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MapWhisper;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use App\Support\Whispers\WhisperLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhisperController extends ApiController
{
    public function remove(
        Request $request,
        MapWhisper $whisper,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
        WhisperLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $guard->actorForWhisper($request->user(), $whisper);
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $whisper->loadMissing(['space', 'membership']);
        $lifecycle->remove($whisper, $actor->id, 'removed_by_owner');

        $logger->log($actor, $whisper->membership, 'remove_whisper', $payload['reason'] ?? null, null, null, [
            'whisperId' => $whisper->public_id,
        ]);

        return $this->ok([
            'whisper' => ApiResource::whisper($whisper->fresh(['space', 'membership', 'image'])),
        ]);
    }
}
