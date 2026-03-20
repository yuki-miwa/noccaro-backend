<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\SpacePostReaction;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
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
    ): JsonResponse {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $payload = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['sometimes', 'boolean'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
        ]);

        $post->fill([
            'title' => $payload['title'] ?? $post->title,
            'body' => $payload['body'] ?? $post->body,
            'status' => $payload['status'] ?? $post->status,
            'notify_members' => $payload['notifyMembers'] ?? $post->notify_members,
            'visible_from' => array_key_exists('visibleFrom', $payload) ? $payload['visibleFrom'] : $post->visible_from,
            'visible_to' => array_key_exists('visibleTo', $payload) ? $payload['visibleTo'] : $post->visible_to,
        ]);

        if (($payload['status'] ?? null) === 'published' && ! $post->published_at) {
            $post->published_at = now();
        }

        $post->save();

        return $this->ok([
            'post' => $this->postPayload($post->fresh(['space', 'authorMembership']), $actor->id),
        ]);
    }

    public function publish(
        Request $request,
        SpacePost $post,
        SpaceAdminGuard $guard,
    ): JsonResponse {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $payload = $request->validate([
            'notifyMembers' => ['nullable', 'boolean'],
        ]);

        $post->forceFill([
            'status' => 'published',
            'notify_members' => (bool) ($payload['notifyMembers'] ?? false),
            'published_at' => $post->published_at ?? now(),
        ])->save();

        if ($post->notify_members) {
            SpaceNotification::query()->create([
                'space_id' => $post->space_id,
                'source_type' => 'post',
                'source_id' => $post->id,
                'created_by_membership_id' => $actor->id,
                'title' => $post->title,
                'body' => mb_substr($post->body, 0, 200),
                'target_scope' => 'all_active_members',
                'status' => 'queued',
            ]);
        }

        return $this->ok([
            'post' => $this->postPayload($post->fresh(['space', 'authorMembership']), $actor->id),
        ]);
    }

    public function archive(Request $request, SpacePost $post, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForPost($request->user(), $post);
        $this->assertOwnerCategory($post);
        $post->forceFill(['status' => 'archived'])->save();

        return $this->ok([
            'post' => $this->postPayload($post->fresh(['space', 'authorMembership']), $actor->id),
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

    private function postPayload(SpacePost $post, int $actorMembershipId): array
    {
        $post->loadCount('reactions');
        $reactedByMe = SpacePostReaction::query()
            ->where('post_id', $post->id)
            ->where('membership_id', $actorMembershipId)
            ->exists();

        return ApiResource::post($post, $reactedByMe);
    }

    private function assertOwnerCategory(SpacePost $post): void
    {
        if ($post->category !== 'owner') {
            throw new ApiException('FORBIDDEN', 'owner カテゴリ以外はこの API では管理できません。', 403);
        }
    }
}
