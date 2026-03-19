<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpacePost;
use App\Models\SpacePostReaction;
use App\Support\Api\ApiResource;
use App\Support\Spaces\MembershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends ApiController
{
    public function index(Request $request, Space $space, MembershipGuard $guard): JsonResponse
    {
        $membership = $guard->requireActiveMembership($request->user(), $space);
        $limit = (int) ($request->integer('limit') ?: 20);

        $posts = SpacePost::query()
            ->with('space')
            ->withCount('reactions')
            ->where('space_id', $space->id)
            ->where('status', 'published')
            ->where(function ($query): void {
                $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('visible_to')->orWhere('visible_to', '>=', now());
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $reactedIds = SpacePostReaction::query()
            ->where('membership_id', $membership->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->all();

        return $this->collection(
            $posts->map(fn (SpacePost $post) => ApiResource::post($post, in_array($post->id, $reactedIds, true)))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function show(Request $request, SpacePost $post, MembershipGuard $guard): JsonResponse
    {
        $post->loadMissing(['space']);
        $this->assertPublished($post);
        $membership = $guard->requireActiveMembership($request->user(), $post->space);
        $post->loadCount('reactions');
        $reactedByMe = SpacePostReaction::query()
            ->where('post_id', $post->id)
            ->where('membership_id', $membership->id)
            ->exists();

        return $this->ok([
            'post' => ApiResource::post($post, $reactedByMe),
        ]);
    }

    public function reaction(Request $request, SpacePost $post, MembershipGuard $guard): JsonResponse
    {
        $payload = $request->validate([
            'reactionType' => ['required', 'in:like'],
            'enabled' => ['required', 'boolean'],
        ]);

        $post->loadMissing(['space']);
        $this->assertPublished($post);
        $membership = $guard->requireActiveMembership($request->user(), $post->space);

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

    private function assertPublished(SpacePost $post): void
    {
        $isVisible = $post->status === 'published'
            && (! $post->visible_from || $post->visible_from->lte(now()))
            && (! $post->visible_to || $post->visible_to->gte(now()));

        if (! $isVisible) {
            throw new ApiException('RESOURCE_NOT_FOUND', '記事が見つかりません。', 404);
        }
    }
}
