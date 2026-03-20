<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\ContentReport;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\SpacePostReaction;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSpaceController extends ApiController
{
    public function show(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForSpace($request->user(), $space);
        $actor->loadMissing(['space', 'user']);

        return $this->ok([
            'space' => ApiResource::space($space),
            'membership' => ApiResource::membership($actor),
        ]);
    }

    public function update(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForSpace($request->user(), $space);

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'joinPolicy' => ['sometimes', Rule::in(['auto_approve', 'approval_required'])],
            'spaceCode' => ['sometimes', 'string', 'max:20', Rule::unique('spaces', 'space_code')->ignore($space->id)],
            'maxOwnerCount' => ['sometimes', 'integer', 'min:1'],
            'whisperTtlMinutes' => ['sometimes', 'integer', 'min:1'],
            'whisperMaxLength' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'locationGridMeters' => ['sometimes', 'integer', 'min:1'],
            'locationJitterEnabled' => ['sometimes', 'boolean'],
            'whisperAutoHideReportThreshold' => ['sometimes', 'integer', 'min:1'],
            'whisperRateLimitPerMinute' => ['sometimes', 'integer', 'min:1'],
            'whisperRateLimitPer10Min' => ['sometimes', 'integer', 'min:1'],
        ]);

        $space->fill([
            'name' => $payload['name'] ?? $space->name,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $space->description,
            'join_policy' => $payload['joinPolicy'] ?? $space->join_policy,
            'space_code' => $payload['spaceCode'] ?? $space->space_code,
            'max_owner_count' => $payload['maxOwnerCount'] ?? $space->max_owner_count,
            'whisper_ttl_minutes' => $payload['whisperTtlMinutes'] ?? $space->whisper_ttl_minutes,
            'whisper_max_length' => $payload['whisperMaxLength'] ?? $space->whisper_max_length,
            'location_grid_meters' => $payload['locationGridMeters'] ?? $space->location_grid_meters,
            'location_jitter_enabled' => $payload['locationJitterEnabled'] ?? $space->location_jitter_enabled,
            'whisper_auto_hide_report_threshold' => $payload['whisperAutoHideReportThreshold'] ?? $space->whisper_auto_hide_report_threshold,
            'whisper_rate_limit_per_minute' => $payload['whisperRateLimitPerMinute'] ?? $space->whisper_rate_limit_per_minute,
            'whisper_rate_limit_per_10min' => $payload['whisperRateLimitPer10Min'] ?? $space->whisper_rate_limit_per_10min,
        ]);
        $space->save();

        $actor->loadMissing(['space', 'user']);

        return $this->ok([
            'space' => ApiResource::space($space->fresh()),
            'membership' => ApiResource::membership($actor),
        ]);
    }

    public function joinRequests(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $guard->actorForSpace($request->user(), $space);

        $items = SpaceMembership::query()
            ->with(['space', 'user'])
            ->where('space_id', $space->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->map(fn (SpaceMembership $membership) => ApiResource::adminJoinRequestItem($membership))
            ->all();

        return $this->ok($items);
    }

    public function members(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $guard->actorForSpace($request->user(), $space);
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = SpaceMembership::query()
            ->with(['space', 'user'])
            ->where('space_id', $space->id)
            ->orderBy('created_at');

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('role') && $request->string('role')->value() !== 'all') {
            $query->where('role', $request->string('role')->value());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->value());
            $query->whereHas('user', function ($userQuery) use ($search): void {
                $userQuery
                    ->where('display_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $memberships = $query->limit($limit)->get();

        return $this->collection(
            $memberships->map(fn (SpaceMembership $membership) => ApiResource::adminMemberItem($membership))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function posts(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForSpace($request->user(), $space);
        $payload = $request->validate([
            'category' => ['nullable', Rule::in(['owner'])],
        ]);
        $category = $payload['category'] ?? 'owner';

        $posts = SpacePost::query()
            ->with(['space', 'authorMembership'])
            ->withCount('reactions')
            ->where('space_id', $space->id)
            ->where('category', $category)
            ->orderByDesc('updated_at')
            ->get();

        $reactedIds = SpacePostReaction::query()
            ->where('membership_id', $actor->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->all();

        return $this->collection(
            $posts->map(
                fn (SpacePost $post) => ApiResource::post($post, in_array($post->id, $reactedIds, true))
            )->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'category' => $category,
            ],
        );
    }

    public function createPost(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForSpace($request->user(), $space);

        $payload = $request->validate([
            'category' => ['nullable', Rule::in(['owner'])],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'notifyMembers' => ['nullable', 'boolean'],
            'visibleFrom' => ['nullable', 'date'],
            'visibleTo' => ['nullable', 'date'],
        ]);

        $post = SpacePost::query()->create([
            'space_id' => $space->id,
            'category' => $payload['category'] ?? 'owner',
            'author_membership_id' => $actor->id,
            'title' => trim($payload['title']),
            'body' => $payload['body'],
            'status' => $payload['status'],
            'notify_members' => (bool) ($payload['notifyMembers'] ?? false),
            'published_at' => $payload['status'] === 'published' ? now() : null,
            'visible_from' => $payload['visibleFrom'] ?? null,
            'visible_to' => $payload['visibleTo'] ?? null,
        ]);

        if ($post->status === 'published' && $post->notify_members) {
            $this->queuePostNotification($post, $actor);
        }

        $post->load(['space', 'authorMembership']);
        $post->loadCount('reactions');

        return $this->ok([
            'post' => ApiResource::post($post),
        ], 201);
    }

    public function reports(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $guard->actorForSpace($request->user(), $space);
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = ContentReport::query()
            ->with(['space', 'reporterMembership.user', 'handledByMembership'])
            ->where('space_id', $space->id)
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('targetType') && $request->string('targetType')->value() !== 'all') {
            $query->where('target_type', $request->string('targetType')->value());
        }

        if ($request->filled('reasonType') && $request->string('reasonType')->value() !== 'all') {
            $query->where('reason_type', $request->string('reasonType')->value());
        }

        $reports = $query->limit($limit)->get();

        return $this->collection(
            $reports->map(fn (ContentReport $report) => ApiResource::adminReportItem($report))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function notifications(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $guard->actorForSpace($request->user(), $space);
        $items = SpaceNotification::query()
            ->with(['space', 'createdByMembership'])
            ->where('space_id', $space->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->collection(
            $items->map(fn (SpaceNotification $notification) => ApiResource::notification($notification))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
            ],
        );
    }

    public function createNotification(Request $request, Space $space, SpaceAdminGuard $guard): JsonResponse
    {
        $actor = $guard->actorForSpace($request->user(), $space);
        $payload = $request->validate([
            'sourceType' => ['required', Rule::in(['system', 'post'])],
            'sourceId' => ['nullable', 'string'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'body' => ['required', 'string'],
            'targetScope' => ['required', Rule::in(['all_active_members', 'owners_only'])],
            'scheduledAt' => ['nullable', 'date'],
        ]);

        $sourceId = null;
        if (($payload['sourceType'] ?? 'system') === 'post' && ! empty($payload['sourceId'])) {
            $post = SpacePost::query()
                ->where('space_id', $space->id)
                ->where('public_id', $payload['sourceId'])
                ->first();

            if (! $post) {
                throw new ApiException('RESOURCE_NOT_FOUND', '通知元の記事が見つかりません。', 404);
            }

            $sourceId = $post->id;
        }

        $notification = SpaceNotification::query()->create([
            'space_id' => $space->id,
            'source_type' => $payload['sourceType'],
            'source_id' => $sourceId,
            'created_by_membership_id' => $actor->id,
            'title' => trim($payload['title']),
            'body' => $payload['body'],
            'target_scope' => $payload['targetScope'],
            'status' => 'queued',
            'scheduled_at' => $payload['scheduledAt'] ?? null,
        ]);
        $notification->load(['space', 'createdByMembership']);

        return $this->ok([
            'notification' => ApiResource::notification($notification),
        ], 201);
    }

    private function queuePostNotification(SpacePost $post, SpaceMembership $actor): void
    {
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
}
