<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Support\Api\ApiResource;
use App\Support\Posts\PostAudienceService;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SystemAdminPostController extends ApiController
{
    public function index(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        PostAudienceService $audienceService,
    ): JsonResponse {
        $guard->actor($request->user());
        $payload = $request->validate([
            'category' => ['nullable', Rule::in(['all', 'owner', 'operation'])],
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
            $this->systemPostItems($posts, $audienceService),
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
        PostAudienceService $audienceService,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $payload = $request->validate([
            'category' => ['required', Rule::in(['operation'])],
            'audienceType' => ['nullable', Rule::in(['all_members', 'targeted_users'])],
            'recipientUserIds' => ['nullable', 'array'],
            'recipientUserIds.*' => ['string', 'uuid'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['nullable', 'boolean'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
        ]);

        $authorMembership = $this->resolvePrimaryOwnerMembership($space);
        $audienceType = $audienceService->normalizeAudienceType($payload['audienceType'] ?? null);
        $notifyMembers = $audienceService->normalizeNotifyMembers($audienceType, (bool) ($payload['notifyMembers'] ?? false));

        $post = SpacePost::query()->create([
            'space_id' => $space->id,
            'category' => $payload['category'],
            'audience_type' => $audienceType,
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
        $post->load('space');
        $recipients = $audienceService->syncRecipients($post, $audienceType, $payload['recipientUserIds'] ?? []);

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
                'audienceType' => $post->audience_type,
                'recipientUserIds' => $recipients->pluck('public_id')->all(),
            ],
        );

        $post->load(['space', 'authorMembership', 'createdBySystemAdmin.user']);
        $post->loadCount('reactions');

        return $this->ok([
            'item' => $this->systemPostItem($post, $recipients->pluck('public_id')->all()),
        ], 201);
    }

    public function update(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        PostAudienceService $audienceService,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space', 'createdBySystemAdmin.user']);

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

        if (array_key_exists('notifyMembers', $payload)) {
            $payload['notifyMembers'] = $audienceService->normalizeNotifyMembers($audienceType, (bool) $payload['notifyMembers']);
        }

        $post->fill([
            'title' => $payload['title'] ?? $post->title,
            'body' => $payload['body'] ?? $post->body,
            'status' => $payload['status'] ?? $post->status,
            'audience_type' => $audienceType,
            'notify_members' => $payload['notifyMembers'] ?? $post->notify_members,
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
                'audienceType' => $post->audience_type,
                'recipientUserIds' => $recipients->pluck('public_id')->all(),
            ],
        );

        return $this->ok([
            'item' => $this->systemPostItem(
                $post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']),
                $recipients->pluck('public_id')->all(),
            ),
        ]);
    }

    public function publish(
        Request $request,
        SpacePost $post,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        PostAudienceService $audienceService,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $post->loadMissing(['space', 'authorMembership']);
        $payload = $request->validate([
            'notifyMembers' => ['nullable', 'boolean'],
        ]);

        $notifyMembers = $audienceService->normalizeNotifyMembers($post->audience_type, (bool) ($payload['notifyMembers'] ?? false));

        $post->forceFill([
            'status' => 'published',
            'notify_members' => $notifyMembers,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        if ($post->notify_members) {
            $this->queuePostNotification($post, $post->authorMembership);
        }

        $recipientUserIds = $this->existingRecipientUserIds($post);

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
                'audienceType' => $post->audience_type,
                'recipientUserIds' => $recipientUserIds,
            ],
        );

        return $this->ok([
            'item' => $this->systemPostItem(
                $post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']),
                $recipientUserIds,
            ),
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
        $recipientUserIds = $this->existingRecipientUserIds($post);

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
            'item' => $this->systemPostItem(
                $post->fresh(['space', 'authorMembership', 'createdBySystemAdmin.user']),
                $recipientUserIds,
            ),
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

    private function systemPostItems(Collection $posts, PostAudienceService $audienceService): array
    {
        $recipientIdsByPost = $audienceService->recipientPublicIdsForPosts($posts);

        return $posts
            ->map(fn (SpacePost $post) => $this->systemPostItem($post, $recipientIdsByPost[$post->id] ?? []))
            ->all();
    }

    private function systemPostItem(SpacePost $post, array $recipientUserIds = []): array
    {
        return [
            'post' => ApiResource::post($post, recipientUserIds: $recipientUserIds),
            'createdBySystemAdmin' => $post->createdBySystemAdmin
                ? ApiResource::systemAdmin($post->createdBySystemAdmin)
                : null,
        ];
    }

    private function existingRecipientUserIds(SpacePost $post): array
    {
        return $post->deliveries()->with('recipient')->get()->map(fn ($delivery) => $delivery->recipient?->public_id)->filter()->values()->all();
    }
}
