<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Support\Api\ApiResource;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceController extends ApiController
{
    public function joined(Request $request): JsonResponse
    {
        $memberships = SpaceMembership::query()
            ->with(['space', 'user'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return $this->ok([
            'joinedSpaces' => $memberships->map(fn (SpaceMembership $membership) => ApiResource::joinedSpace($membership))->all(),
        ]);
    }

    public function show(Request $request, Space $space, MembershipGuard $guard): JsonResponse
    {
        $membership = $guard->requireMembership($request->user(), $space);
        $membership->loadMissing(['space', 'user']);

        return $this->ok([
            'space' => ApiResource::space($space),
            'membership' => ApiResource::membership($membership),
        ]);
    }

    public function membership(Request $request, Space $space, MembershipGuard $guard): JsonResponse
    {
        $membership = $guard->requireMembership($request->user(), $space);
        $membership->loadMissing(['space', 'user']);

        return $this->ok([
            'membership' => ApiResource::membership($membership),
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'spaceCode' => ['required', 'string', 'max:20'],
        ]);

        $space = Space::query()
            ->where('space_code', trim($payload['spaceCode']))
            ->where('status', 'active')
            ->first();

        if (! $space) {
            throw new ApiException('SPACE_CODE_NOT_FOUND', 'スペースコードが見つかりません。', 404, ['field' => 'spaceCode']);
        }

        $membership = SpaceMembership::query()
            ->with(['space', 'user'])
            ->firstOrNew([
                'space_id' => $space->id,
                'user_id' => $request->user()->id,
            ]);

        if ($membership->exists && $membership->status === 'banned') {
            throw new ApiException('MEMBERSHIP_BANNED', 'BAN されているため再参加できません。', 403);
        }

        if (! $membership->exists || in_array($membership->status, ['left', 'kicked', 'rejected'], true)) {
            $approved = $space->join_policy === 'auto_approve';
            $membership->fill([
                'role' => 'guest',
                'status' => $approved ? 'active' : 'pending',
                'joined_at' => $approved ? now() : null,
                'approved_at' => $approved ? now() : null,
                'approved_by_membership_id' => null,
                'left_at' => null,
                'kicked_at' => null,
                'suspended_until' => null,
                'banned_at' => null,
                'mute_until' => null,
                'last_seen_at' => now(),
            ]);
            $membership->save();
        }

        $membership->loadMissing(['space', 'user']);

        return $this->ok([
            'space' => ApiResource::space($space),
            'membership' => ApiResource::membership($membership),
        ]);
    }
}
