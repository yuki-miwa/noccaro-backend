<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\SpacePostDelivery;
use App\Models\User;
use App\Support\Api\ApiResource;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SystemAdminPostController extends ApiController
{
    public function index(Request $request, Space $space, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $payload = $request->validate([
            'category' => ['nullable', Rule::in(['all', 'owner', 'operation', 'personal'])],
        ]);
        $category = $payload['category'] ?? 'all';

        $query = SpacePost::query()
            ->with(['space', 'authorMembership', 'createdBySystemAdmin.user'])
            ->where('space_id', $space->id)
            ->orderByDesc('updated_at');

        if ($category !== 'all') {
            $query->where('category', $category);
        }

        $posts = $query->get();

        return $this->collection(
            $this->systemPostItems($posts),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'category' => $category,
            ],
        );
    }

    public function store(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $payload = $request->validate([
            'category' => ['required', Rule::in(['operation', 'personal'])],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['nullable', 'boolean'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
            'recipientUserId' => ['nullable', 'uuid'],
        ]);

        $authorMembership = $this->resolvePrimaryOwnerMembership($space);
        $recipient = $this->resolveRecipient($space, $payload['category'], $payload['recipientUserId'] ?? null);
        $notifyMembers = $this->normalizeNotifyMembers($payload['category'], (bool) ($payload['notifyMembers'] ?? false));

        $post = SpacePost::query()->create([
            'space_id' => $space->id,
            'category' => $payload['category'],
            'author_membership_id' => $authorMembership->id,
            'created_by_system_admin_id' => $actor->id,
            'title' => trim($payload['title']),
            'body' => $payload['body'],
            'status' => $payload['status'],
            'notify_members' => $notifyMembers,
            'published_at' => $payload['status'] === 'published' ? now() : null,
            'visible_from' => $payload['visibleFrom'] ?? null,
            'visible_to' => $payload['visibleTo'] ?? null,
        ]);

        if ($recipient) {
            SpacePostDelivery::query()->create([
                'post_id' => $post->id,
                'recipient_user_id' => $recipient->id,
            ]);
        }

        if ($post->status === 'published' && $post->notify_members) {
            $this->queuePostNotification($post, $authorMembership);
        }

        $logger->log(
            $actor,
            'post_created',
            'post',
            $post->public_id,
            sprintf('%s カテゴリのお知らせを作成しました。', $post->category),
            [
                'spaceId' => $space->public_id,
                'postId' => $post->public_id,
                'category' => $post->category,
                'recipientUserId' => $recipient?->public_id,
            ],
        );

        $post->load(['space', 'authorMembership', 'createdBySystemAdmin.user']);
        $post->loadCount('reactions');

        return $this->ok([
            'item' => $this->systemPostItem($post, $recipient),
        ], 201);
    }

    public function update(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space', 'createdBySystemAdmin.user']);

        $payload = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['sometimes', 'boolean'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
            'recipientUserId' => ['nullable', 'uuid'],
        ]);

        if (array_key_exists('notifyMembers', $payload)) {
            $payload['notifyMembers'] = $this->normalizeNotifyMembers($post->category, (bool) $payload['notifyMembers']);
        }

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

        $recipient = $this->syncRecipient($post, $payload['recipientUserId'] ?? null);

        $logger->log(
            $actor,
            'post_updated',
            'post',
            $post->public_id,
            sprintf('%s カテゴリのお知らせを更新しました。', $post->category),
            [
                'spaceId' => $post->space->public_id,
                'postId' => $post->public_id,
                'category' => $post->category,
                'recipientUserId' => $recipient?->public_id,
            ],
        );

        return $this->ok([
            'item' => $this->systemPostItem($post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']), $recipient),
        ]);
    }

    public function publish(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space', 'authorMembership']);
        $payload = $request->validate([
            'notifyMembers' => ['nullable', 'boolean'],
        ]);

        $notifyMembers = $this->normalizeNotifyMembers($post->category, (bool) ($payload['notifyMembers'] ?? false));

        $post->forceFill([
            'status' => 'published',
            'notify_members' => $notifyMembers,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        if ($post->notify_members) {
            $this->queuePostNotification($post, $post->authorMembership);
        }

        $recipient = $this->currentRecipient($post);

        $logger->log(
            $actor,
            'post_published',
            'post',
            $post->public_id,
            sprintf('%s カテゴリのお知らせを公開しました。', $post->category),
            [
                'spaceId' => $post->space->public_id,
                'postId' => $post->public_id,
                'category' => $post->category,
                'recipientUserId' => $recipient?->public_id,
            ],
        );

        return $this->ok([
            'item' => $this->systemPostItem($post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']), $recipient),
        ]);
    }

    public function archive(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space']);
        $post->forceFill(['status' => 'archived'])->save();
        $recipient = $this->currentRecipient($post);

        $logger->log(
            $actor,
            'post_archived',
            'post',
            $post->public_id,
            sprintf('%s カテゴリのお知らせをアーカイブしました。', $post->category),
            [
                'spaceId' => $post->space->public_id,
                'postId' => $post->public_id,
                'category' => $post->category,
            ],
        );

        return $this->ok([
            'item' => $this->systemPostItem($post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']), $recipient),
        ]);
    }

    public function destroy(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): Response {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space']);

        $logger->log(
            $actor,
            'post_deleted',
            'post',
            $post->public_id,
            sprintf('%s カテゴリのお知らせを削除しました。', $post->category),
            [
                'spaceId' => $post->space->public_id,
                'postId' => $post->public_id,
                'category' => $post->category,
            ],
        );

        $post->forceFill(['status' => 'deleted'])->save();
        $post->delete();

        return $this->noContent();
    }

    private function resolvePrimaryOwnerMembership(Space $space): SpaceMembership
    {
        $membership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->where('role', 'primary_owner')
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new ApiException('CONFLICT', '主オーナーが設定されていないスペースにはお知らせを作成できません。', 409);
        }

        return $membership;
    }

    private function resolveRecipient(Space $space, string $category, ?string $recipientUserId): ?User
    {
        if ($category !== 'personal') {
            return null;
        }

        if (! $recipientUserId) {
            throw new ApiException('VALIDATION_ERROR', 'personal お知らせには recipientUserId が必要です。', 422, [
                'field' => 'recipientUserId',
            ]);
        }

        $recipient = User::query()->where('public_id', $recipientUserId)->first();
        if (! $recipient) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象ユーザーが見つかりません。', 404);
        }

        $membership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->where('user_id', $recipient->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new ApiException('CONFLICT', 'active member のみ personal お知らせの対象にできます。', 409);
        }

        return $recipient;
    }

    private function normalizeNotifyMembers(string $category, bool $notifyMembers): bool
    {
        if ($category === 'personal' && $notifyMembers) {
            throw new ApiException('VALIDATION_ERROR', 'personal お知らせでは notifyMembers を true にできません。', 422, [
                'field' => 'notifyMembers',
            ]);
        }

        return $category === 'personal' ? false : $notifyMembers;
    }

    private function syncRecipient(SpacePost $post, ?string $recipientUserId): ?User
    {
        if ($post->category !== 'personal') {
            return null;
        }

        $recipient = $this->resolveRecipient($post->space, 'personal', $recipientUserId ?: $this->currentRecipient($post)?->public_id);

        if (! $recipient) {
            return null;
        }

        SpacePostDelivery::query()->updateOrCreate([
            'post_id' => $post->id,
        ], [
            'recipient_user_id' => $recipient->id,
        ]);

        return $recipient;
    }

    private function currentRecipient(SpacePost $post): ?User
    {
        return SpacePostDelivery::query()
            ->with('recipient')
            ->where('post_id', $post->id)
            ->first()?->recipient;
    }

    private function queuePostNotification(SpacePost $post, SpaceMembership $authorMembership): void
    {
        SpaceNotification::query()->create([
            'space_id' => $post->space_id,
            'source_type' => 'post',
            'source_id' => $post->id,
            'created_by_membership_id' => $authorMembership->id,
            'title' => $post->title,
            'body' => mb_substr($post->body, 0, 200),
            'target_scope' => 'all_active_members',
            'status' => 'queued',
        ]);
    }

    private function systemPostItems(Collection $posts): array
    {
        $deliveryMap = SpacePostDelivery::query()
            ->with('recipient')
            ->whereIn('post_id', $posts->pluck('id'))
            ->get()
            ->keyBy('post_id');

        return $posts
            ->map(fn (SpacePost $post) => $this->systemPostItem($post, $deliveryMap->get($post->id)?->recipient))
            ->all();
    }

    private function systemPostItem(SpacePost $post, ?User $recipient = null): array
    {
        return [
            'post' => ApiResource::post($post),
            'recipient' => $recipient ? ApiResource::user($recipient) : null,
            'createdBySystemAdmin' => $post->createdBySystemAdmin
                ? ApiResource::systemAdmin($post->createdBySystemAdmin)
                : null,
        ];
    }
}
