<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpacePost;
use App\Models\SpacePostDelivery;
use App\Models\SpacePostReaction;
use App\Models\SpacePostRead;
use App\Support\Api\ApiResource;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostController extends ApiController
{
    public function index(Request $request, Space $space, MembershipGuard $guard): JsonResponse
    {
        $membership = $guard->requireActiveMembership($request->user(), $space);
        $payload = $request->validate([
            'category' => ['nullable', Rule::in(['owner', 'operation', 'personal'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($request->integer('limit') ?: 20);
        $category = $payload['category'] ?? 'owner';

        $query = SpacePost::query()
            ->with('space')
            ->withCount('reactions')
            ->where('space_id', $space->id)
            ->where('category', $category)
            ->publishedVisible();

        if ($category === 'personal') {
            $query->whereHas('deliveries', function ($deliveryQuery) use ($request): void {
                $deliveryQuery->where('recipient_user_id', $request->user()->id);
            });
        }

        $posts = $query
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $reactedIds = SpacePostReaction::query()
            ->where('membership_id', $membership->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->all();
        $readMap = SpacePostRead::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->get()
            ->keyBy('post_id');

        return $this->collection(
            $posts->map(
                fn (SpacePost $post) => ApiResource::post(
                    $post,
                    reactedByMe: in_array($post->id, $reactedIds, true),
                    isRead: $readMap->has($post->id),
                    readAt: ApiResource::iso($readMap->get($post->id)?->read_at),
                )
            )->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
                'category' => $category,
            ],
        );
    }

    public function show(Request $request, SpacePost $post, MembershipGuard $guard): JsonResponse
    {
        $post->loadMissing(['space', 'authorMembership']);
        $membership = $this->authorizeVisiblePost($request, $post, $guard);
        $post->loadCount('reactions');
        $reactedByMe = SpacePostReaction::query()
            ->where('post_id', $post->id)
            ->where('membership_id', $membership->id)
            ->exists();
        $read = SpacePostRead::query()
            ->where('post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return $this->ok([
            'post' => ApiResource::post(
                $post,
                reactedByMe: $reactedByMe,
                isRead: $read !== null,
                readAt: ApiResource::iso($read?->read_at),
            ),
        ]);
    }

    public function reaction(Request $request, SpacePost $post, MembershipGuard $guard): JsonResponse
    {
        $payload = $request->validate([
            'reactionType' => ['required', 'in:like'],
            'enabled' => ['required', 'boolean'],
        ]);

        $post->loadMissing(['space']);
        $membership = $this->authorizeVisiblePost($request, $post, $guard);

        if ($payload['enabled']) {
            SpacePostReaction::query()->firstOrCreate([
                'post_id' => $post->id,
                'membership_id' => $membership->id,
            ], [
                'reaction_type' => $payload['reactionType'],
                'created_at' => now(),
            ]);
        } else {
            SpacePostReaction::query()
                ->where('post_id', $post->id)
                ->where('membership_id', $membership->id)
                ->delete();
        }

        $reactionCount = SpacePostReaction::query()->where('post_id', $post->id)->count();

        return $this->ok([
            'postId' => $post->public_id,
            'reactionCount' => $reactionCount,
            'reactedByMe' => (bool) $payload['enabled'],
        ]);
    }

    public function read(Request $request, SpacePost $post, MembershipGuard $guard): JsonResponse
    {
        $this->authorizeVisiblePost($request, $post, $guard);

        $existingRead = SpacePostRead::query()
            ->where('post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $existingRead) {
            $existingRead = SpacePostRead::query()->create([
                'post_id' => $post->id,
                'user_id' => $request->user()->id,
                'read_at' => now(),
            ]);
        }

        return $this->ok([
            'postId' => $post->public_id,
            'isRead' => true,
            'readAt' => ApiResource::iso($existingRead->read_at),
        ]);
    }

    private function assertPublished(SpacePost $post): void
    {
        $isVisible = $post->status === 'published'
            && (! $post->visible_from || $post->visible_from->lte(now()))
            && (! $post->visible_to || $post->visible_to->gte(now()));

        if (! $isVisible) {
            throw new ApiException('RESOURCE_NOT_FOUND', '記事が見つかりません。', 404);
        }
    }

    private function authorizeVisiblePost(
        Request $request,
        SpacePost $post,
        MembershipGuard $guard,
    ) {
        $this->assertPublished($post);
        $membership = $guard->requireActiveMembership($request->user(), $post->space);

        if ($post->category === 'personal') {
            $delivered = SpacePostDelivery::query()
                ->where('post_id', $post->id)
                ->where('recipient_user_id', $request->user()->id)
                ->exists();

            if (! $delivered) {
                throw new ApiException('RESOURCE_NOT_FOUND', '記事が見つかりません。', 404);
            }
        }

        return $membership;
    }
}
