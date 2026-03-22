<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SpacePost;
use App\Models\SpacePostReaction;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use App\Support\Notifications\NoticePushService;
use App\Support\Posts\PostAudienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AdminPostController extends ApiController
{
    public function update(
        Request $request,
        SpacePost $post,
        SpaceAdminGuard $guard,
        PostAudienceService $audienceService,
        NoticePushService $pushService,
    ): JsonResponse {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $post->loadMissing('space');
        $previousStatus = $post->status;
        $previousNotifyMembers = $post->notify_members;
        $payload = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['sometimes', 'boolean'],
            'audienceType' => ['nullable', Rule::in(['all_members', 'targeted_users'])],
            'recipientUserIds' => ['nullable', 'array'],
            'recipientUserIds.*' => ['string', 'uuid'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
        ]);
        $audienceType = $audienceService->normalizeAudienceType($payload['audienceType'] ?? $post->audience_type);
        $notifyMembers = array_key_exists('notifyMembers', $payload)
            ? $audienceService->normalizeNotifyMembers($audienceType, (bool) $payload['notifyMembers'])
            : $post->notify_members;

        $post->fill([
            'title' => $payload['title'] ?? $post->title,
            'body' => $payload['body'] ?? $post->body,
            'status' => $payload['status'] ?? $post->status,
            'audience_type' => $audienceType,
            'notify_members' => $notifyMembers,
            'visible_from' => array_key_exists('visibleFrom', $payload) ? $payload['visibleFrom'] : $post->visible_from,
            'visible_to' => array_key_exists('visibleTo', $payload) ? $payload['visibleTo'] : $post->visible_to,
        ]);

        if (($payload['status'] ?? null) === 'published' && ! $post->published_at) {
            $post->published_at = now();
        }

        $post->save();
        $recipients = $audienceService->syncRecipients(
            $post,
            $audienceType,
            $payload['recipientUserIds'] ?? $this->existingRecipientUserIds($post),
        );

        if ($post->notify_members && $this->shouldQueuePostNotification($previousStatus, $previousNotifyMembers, $post)) {
            $pushService->queuePostNotification($post, $actor);
        }

        return $this->ok([
            'post' => $this->postPayload(
                $post->fresh(['space', 'authorMembership']),
                $actor->id,
                $recipients->pluck('public_id')->all(),
            ),
        ]);
    }

    public function publish(
        Request $request,
        SpacePost $post,
        SpaceAdminGuard $guard,
        PostAudienceService $audienceService,
        NoticePushService $pushService,
    ): JsonResponse {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $previousStatus = $post->status;
        $previousNotifyMembers = $post->notify_members;
        $payload = $request->validate([
            'notifyMembers' => ['nullable', 'boolean'],
        ]);
        $notifyMembers = $audienceService->normalizeNotifyMembers($post->audience_type, (bool) ($payload['notifyMembers'] ?? false));

        $post->forceFill([
            'status' => 'published',
            'notify_members' => $notifyMembers,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        if ($post->notify_members && $this->shouldQueuePostNotification($previousStatus, $previousNotifyMembers, $post)) {
            $pushService->queuePostNotification($post, $actor);
        }

        return $this->ok([
            'post' => $this->postPayload(
                $post->fresh(['space', 'authorMembership']),
                $actor->id,
                $this->existingRecipientUserIds($post),
            ),
        ]);
    }

    public function archive(Request $request, SpacePost $post, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $post->forceFill(['status' => 'archived'])->save();

        return $this->ok([
            'post' => $this->postPayload(
                $post->fresh(['space', 'authorMembership']),
                $actor->id,
                $this->existingRecipientUserIds($post),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        SpacePost $post,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): Response {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $post->loadMissing(['authorMembership']);

        $post->forceFill(['status' => 'deleted'])->save();
        $post->delete();

        $logger->log($actor, $post->authorMembership, 'remove_post', 'owner deleted post', null, null, [
            'postId' => $post->public_id,
        ]);

        return $this->noContent();
    }

    private function postPayload(SpacePost $post, int $actorMembershipId, array $recipientUserIds = []): array
    {
        $post->loadCount('reactions');
        $reactedByMe = SpacePostReaction::query()
            ->where('post_id', $post->id)
            ->where('membership_id', $actorMembershipId)
            ->exists();

        return ApiResource::post($post, reactedByMe: $reactedByMe, recipientUserIds: $recipientUserIds);
    }

    private function assertOwnerCategory(SpacePost $post): void
    {
        if ($post->category !== 'owner') {
            throw new ApiException('FORBIDDEN', 'owner カテゴリ以外はこの API では管理できません。', 403);
        }
    }

    private function existingRecipientUserIds(SpacePost $post): array
    {
        return $post->deliveries()->with('recipient')->get()->map(fn ($delivery) => $delivery->recipient?->public_id)->filter()->values()->all();
    }

    private function shouldQueuePostNotification(string $previousStatus, bool $previousNotifyMembers, SpacePost $post): bool
    {
        $becamePublished = $previousStatus !== 'published' && $post->status === 'published';
        $notificationsBecameEnabled = ! $previousNotifyMembers && $post->notify_members;

        return $post->status === 'published' && ($becamePublished || $notificationsBecameEnabled);
    }
}
