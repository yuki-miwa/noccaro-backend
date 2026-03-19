<?php

namespace App\Support\Admin;

use App\Exceptions\ApiException;
use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\User;

class SpaceAdminGuard
{
    public function actorForSpace(User $user, Space $space): SpaceMembership
    {
        $membership = SpaceMembership::query()
            ->with(['space', 'user'])
            ->where('space_id', $space->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership || ! in_array($membership->role, ['owner', 'primary_owner'], true) || $membership->status !== 'active') {
            throw new ApiException('FORBIDDEN', 'この管理操作を実行する権限がありません。', 403);
        }

        return $membership;
    }

    public function actorForMembership(User $user, SpaceMembership $target): SpaceMembership
    {
        $target->loadMissing('space');

        return $this->actorForSpace($user, $target->space);
    }

    public function actorForPost(User $user, SpacePost $post): SpaceMembership
    {
        $post->loadMissing('space');

        return $this->actorForSpace($user, $post->space);
    }

    public function actorForWhisper(User $user, MapWhisper $whisper): SpaceMembership
    {
        $whisper->loadMissing('space');

        return $this->actorForSpace($user, $whisper->space);
    }

    public function actorForReport(User $user, ContentReport $report): SpaceMembership
    {
        $report->loadMissing('space');

        return $this->actorForSpace($user, $report->space);
    }

    public function actorForNotification(User $user, SpaceNotification $notification): SpaceMembership
    {
        $notification->loadMissing('space');

        return $this->actorForSpace($user, $notification->space);
    }

    public function requirePrimaryOwner(SpaceMembership $actor): void
    {
        if ($actor->role !== 'primary_owner') {
            throw new ApiException('FORBIDDEN', '主オーナーのみ実行できます。', 403);
        }
    }
}
