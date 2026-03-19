<?php

namespace App\Support\Spaces;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\User;

class MembershipGuard
{
    public function findMembership(User $user, Space $space): ?SpaceMembership
    {
        return SpaceMembership::query()
            ->with(['space', 'user'])
            ->where('space_id', $space->id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function requireMembership(User $user, Space $space): SpaceMembership
    {
        $membership = $this->findMembership($user, $space);
        if (! $membership) {
            throw new ApiException('FORBIDDEN', 'このスペースへアクセスできません。', 403);
        }

        return $membership;
    }

    public function requireActiveMembership(User $user, Space $space): SpaceMembership
    {
        $membership = $this->requireMembership($user, $space);

        if ($membership->status === 'banned') {
            throw new ApiException('MEMBERSHIP_BANNED', 'このスペースでは利用停止中です。', 403);
        }

        if ($membership->status === 'kicked') {
            throw new ApiException('MEMBERSHIP_KICKED', 'このスペースから退出処理されています。', 403);
        }

        if ($membership->status === 'suspended') {
            throw new ApiException('MEMBERSHIP_SUSPENDED', 'このスペースでは一時停止中です。', 403);
        }

        if ($membership->status !== 'active') {
            throw new ApiException('FORBIDDEN', 'アクティブなメンバーのみ利用できます。', 403);
        }

        return $membership;
    }
}
