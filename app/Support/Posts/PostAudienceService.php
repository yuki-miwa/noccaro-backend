<?php

namespace App\Support\Posts;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpacePost;
use App\Models\SpacePostDelivery;
use App\Models\User;
use Illuminate\Support\Collection;

class PostAudienceService
{
    public function normalizeAudienceType(?string $audienceType): string
    {
        return $audienceType ?: 'all_members';
    }

    public function normalizeNotifyMembers(string $audienceType, bool $notifyMembers): bool
    {
        if ($audienceType === 'targeted_users' && $notifyMembers) {
            throw new ApiException('VALIDATION_ERROR', 'targeted_users のお知らせでは notifyMembers を true にできません。', 422, [
                'field' => 'notifyMembers',
            ]);
        }

        return $audienceType === 'targeted_users' ? false : $notifyMembers;
    }

    public function resolveRecipientUsers(Space $space, array $recipientUserIds): Collection
    {
        if ($recipientUserIds === []) {
            throw new ApiException('VALIDATION_ERROR', 'targeted_users のお知らせには recipientUserIds が必要です。', 422, [
                'field' => 'recipientUserIds',
            ]);
        }

        $publicIds = array_values(array_unique($recipientUserIds));

        $users = User::query()
            ->whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');

        if ($users->count() !== count($publicIds)) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象ユーザーが見つかりません。', 404);
        }

        $memberships = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->where('status', 'active')
            ->pluck('user_id')
            ->all();

        if (count($memberships) !== $users->count()) {
            throw new ApiException('CONFLICT', 'active member のみ targeted_users の対象にできます。', 409);
        }

        return collect($publicIds)->map(fn (string $publicId) => $users->get($publicId));
    }

    public function syncRecipients(SpacePost $post, string $audienceType, array $recipientUserIds): Collection
    {
        if ($audienceType !== 'targeted_users') {
            SpacePostDelivery::query()->where('post_id', $post->id)->delete();

            return collect();
        }

        $recipients = $this->resolveRecipientUsers($post->space, $recipientUserIds);
        $recipientIds = $recipients->pluck('id')->all();

        SpacePostDelivery::query()
            ->where('post_id', $post->id)
            ->whereNotIn('recipient_user_id', $recipientIds)
            ->delete();

        foreach ($recipientIds as $recipientId) {
            SpacePostDelivery::query()->firstOrCreate([
                'post_id' => $post->id,
                'recipient_user_id' => $recipientId,
            ]);
        }

        return $recipients->values();
    }

    public function recipientPublicIdsForPosts(Collection $posts): array
    {
        return SpacePostDelivery::query()
            ->with('recipient')
            ->whereIn('post_id', $posts->pluck('id'))
            ->get()
            ->groupBy('post_id')
            ->map(fn (Collection $items) => $items->map(fn (SpacePostDelivery $delivery) => $delivery->recipient?->public_id)->filter()->values()->all())
            ->all();
    }

    public function isVisibleToUser(SpacePost $post, User $user): bool
    {
        if ($post->audience_type !== 'targeted_users') {
            return true;
        }

        return SpacePostDelivery::query()
            ->where('post_id', $post->id)
            ->where('recipient_user_id', $user->id)
            ->exists();
    }
}
