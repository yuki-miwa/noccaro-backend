<?php

namespace App\Support\Live;

use App\Exceptions\ApiException;
use App\Models\LiveStreamSession;
use App\Models\LiveThread;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SystemAdmin;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class LiveThreadService
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function activeThreadForSpace(Space $space): ?LiveThread
    {
        return LiveThread::query()
            ->where('space_id', $space->id)
            ->where('status', 'active')
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    public function activeStreamForSpace(Space $space): ?LiveStreamSession
    {
        return LiveStreamSession::query()
            ->where('space_id', $space->id)
            ->where('status', 'live')
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    public function currentStateForSpace(Space $space): array
    {
        return [
            'liveThread' => $this->activeThreadForSpace($space),
            'liveStream' => $this->activeStreamForSpace($space),
        ];
    }

    public function permissionsForMembership(
        SpaceMembership $membership,
        ?LiveThread $thread,
        ?LiveStreamSession $stream,
    ): array {
        $isPrimaryOwner = $membership->status === 'active' && $membership->role === 'primary_owner';
        $hasActiveThread = $thread?->status === 'active';
        $hasLiveStream = $stream?->status === 'live';

        return [
            'canWatch' => $membership->status === 'active' && $hasActiveThread,
            'canComment' => $membership->status === 'active' && $hasActiveThread,
            'canStartThread' => $isPrimaryOwner && ! $hasActiveThread,
            'canCloseThread' => $isPrimaryOwner && $hasActiveThread,
            'canStartStream' => $isPrimaryOwner && $hasActiveThread && ! $hasLiveStream,
            'canEndStream' => $isPrimaryOwner && $hasLiveStream,
            'isPrimaryOwner' => $isPrimaryOwner,
        ];
    }

    public function chatPolicy(): array
    {
        return [
            'roomId' => (string) config('live.chat.room_id'),
            'endpoint' => (string) config('live.chat.endpoint'),
            'messageMaxLength' => (int) config('live.chat.message_max_length', 30),
            'cooldownSeconds' => (int) config('live.chat.cooldown_seconds', 3),
        ];
    }

    public function startThread(Space $space, SpaceMembership $actor): LiveThread
    {
        return $this->db->transaction(function () use ($space, $actor): LiveThread {
            $existing = $this->activeThreadForSpace($space);
            if ($existing) {
                return $existing;
            }

            return LiveThread::query()->create([
                'space_id' => $space->id,
                'status' => 'active',
                'created_by_membership_id' => $actor->id,
                'starts_at' => now(),
            ]);
        });
    }

    public function closeThread(
        Space $space,
        ?SpaceMembership $actor = null,
        ?SystemAdmin $systemAdmin = null,
        string $reason = 'closed',
    ): array {
        return $this->db->transaction(function () use ($space, $actor, $reason, $systemAdmin): array {
            $thread = $this->activeThreadForSpace($space);
            $stream = $this->activeStreamForSpace($space);

            if ($stream) {
                $stream = $this->endActiveStream($stream, $actor, $systemAdmin, $reason === 'force_closed' ? 'force_ended' : 'thread_closed');
            }

            if (! $thread) {
                return [
                    'liveThread' => null,
                    'liveStream' => $stream,
                ];
            }

            $thread->forceFill([
                'status' => 'closed',
                'closed_by_membership_id' => $actor?->id,
                'closed_by_system_admin_id' => $systemAdmin?->id,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();

            return [
                'liveThread' => $thread->fresh(),
                'liveStream' => $stream,
            ];
        });
    }

    public function startStream(Space $space, SpaceMembership $actor): LiveStreamSession
    {
        return $this->db->transaction(function () use ($space, $actor): LiveStreamSession {
            $existing = $this->activeStreamForSpace($space);
            if ($existing) {
                return $existing;
            }

            $thread = $this->activeThreadForSpace($space);
            if (! $thread) {
                throw new ApiException('LIVE_THREAD_NOT_ACTIVE', 'ライブスレッドが開始されていません。', 409);
            }

            if (! config('live.stream_key')) {
                throw new ApiException('LIVE_STREAM_UNAVAILABLE', 'ライブ配信設定が未完了です。', 503);
            }

            return LiveStreamSession::query()->create([
                'live_thread_id' => $thread->id,
                'space_id' => $space->id,
                'status' => 'live',
                'ivs_channel_arn' => (string) config('live.channel_arn'),
                'ivs_playback_url' => (string) config('live.playback_url'),
                'ivs_ingest_endpoint' => (string) config('live.ingest_endpoint'),
                'started_by_membership_id' => $actor->id,
                'started_at' => now(),
            ]);
        });
    }

    public function endStream(
        Space $space,
        ?SpaceMembership $actor = null,
        ?SystemAdmin $systemAdmin = null,
        string $reason = 'ended',
    ): ?LiveStreamSession {
        return $this->db->transaction(function () use ($space, $actor, $reason, $systemAdmin): ?LiveStreamSession {
            $stream = $this->activeStreamForSpace($space);
            if (! $stream) {
                return null;
            }

            return $this->endActiveStream($stream, $actor, $systemAdmin, $reason);
        });
    }

    public function activeSummaries(int $limit = 50, string $status = 'active'): Collection
    {
        $query = Space::query()->with([
            'memberships.user',
            'liveThreads' => fn ($threadQuery) => $threadQuery->latest('starts_at')->latest('id'),
            'liveStreamSessions' => fn ($streamQuery) => $streamQuery->latest('started_at')->latest('id'),
        ]);

        if ($status === 'active') {
            $query->where(function ($spaceQuery): void {
                $spaceQuery
                    ->whereHas('liveThreads', fn ($threadQuery) => $threadQuery->where('status', 'active'))
                    ->orWhereHas('liveStreamSessions', fn ($streamQuery) => $streamQuery->where('status', 'live'));
            });
        }

        return $query->orderByDesc('created_at')->limit($limit)->get()->map(function (Space $space): array {
            $thread = $space->liveThreads->firstWhere('status', 'active');
            $stream = $space->liveStreamSessions->firstWhere('status', 'live');

            return [
                'space' => $space,
                'liveThread' => $thread,
                'liveStream' => $stream,
            ];
        });
    }

    private function endActiveStream(
        LiveStreamSession $stream,
        ?SpaceMembership $actor,
        ?SystemAdmin $systemAdmin,
        string $reason,
    ): LiveStreamSession {
        $stream->forceFill([
            'status' => 'ended',
            'ended_by_membership_id' => $actor?->id,
            'ended_by_system_admin_id' => $systemAdmin?->id,
            'ended_at' => now(),
            'end_reason' => $reason,
        ])->save();

        return $stream->fresh();
    }
}
