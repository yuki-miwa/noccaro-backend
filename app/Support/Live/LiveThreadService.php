<?php

namespace App\Support\Live;

use App\Exceptions\ApiException;
use App\Models\LiveStreamSession;
use App\Models\LiveThread;
use App\Models\LiveThreadSchedule;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SystemAdmin;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class LiveThreadService
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function currentScheduleForSpace(Space $space): ?LiveThreadSchedule
    {
        $schedule = LiveThreadSchedule::query()
            ->where('space_id', $space->id)
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (! $schedule) {
            return null;
        }

        return $this->refreshScheduleStatus($schedule);
    }

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

    public function stateForSpace(
        Space $space,
        SpaceMembership $membership,
        ?float $currentLat = null,
        ?float $currentLng = null,
    ): array {
        $schedule = $this->currentScheduleForSpace($space);
        $thread = $this->activeThreadForSpace($space);
        $stream = $this->activeStreamForSpace($space);

        return [
            'scheduledThread' => $schedule,
            'liveThread' => $thread,
            'liveStream' => $stream,
            'permissions' => $this->permissionsForMembership($membership, $thread, $stream),
            'eligibility' => $this->eligibilityForMembership($membership, $schedule, $thread, $currentLat, $currentLng),
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

    public function eligibilityForMembership(
        SpaceMembership $membership,
        ?LiveThreadSchedule $schedule,
        ?LiveThread $thread,
        ?float $currentLat = null,
        ?float $currentLng = null,
    ): array {
        $windowOpen = $schedule ? $this->windowOpen($schedule) : false;
        $distanceMeters = null;
        $insideStartArea = null;
        $reasonCode = null;

        if ($membership->status !== 'active' || $membership->role !== 'primary_owner') {
            $reasonCode = 'FORBIDDEN';
        } elseif ($thread?->status === 'active') {
            $reasonCode = 'LIVE_THREAD_ALREADY_ACTIVE';
        } elseif (! $schedule) {
            $reasonCode = 'LIVE_THREAD_SCHEDULE_NOT_FOUND';
        } elseif ($this->windowNotOpenedYet($schedule)) {
            $reasonCode = 'LIVE_THREAD_WINDOW_NOT_OPEN';
        } elseif ($this->windowExpired($schedule)) {
            $reasonCode = 'LIVE_THREAD_WINDOW_EXPIRED';
        } elseif ($currentLat !== null && $currentLng !== null) {
            $distanceMeters = round(
                $this->distanceMeters(
                    $schedule->area_center_lat,
                    $schedule->area_center_lng,
                    $currentLat,
                    $currentLng,
                ),
                1,
            );
            $insideStartArea = $distanceMeters <= $schedule->area_radius_m;
            if (! $insideStartArea) {
                $reasonCode = 'LIVE_THREAD_OUT_OF_AREA';
            }
        }

        return [
            'canStartThreadNow' => $reasonCode === null && $insideStartArea === true,
            'insideStartArea' => $insideStartArea,
            'distanceMeters' => $distanceMeters,
            'windowOpen' => $windowOpen,
            'reasonCode' => $reasonCode,
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

    public function upsertSchedule(Space $space, SpaceMembership $actor, array $attributes): LiveThreadSchedule
    {
        return $this->db->transaction(function () use ($space, $actor, $attributes): LiveThreadSchedule {
            $schedule = LiveThreadSchedule::query()->firstOrNew(['space_id' => $space->id]);

            if (! $schedule->exists) {
                $schedule->created_by_membership_id = $actor->id;
            }

            $schedule->fill([
                'status' => 'scheduled',
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'area_center_lat' => $attributes['area_center_lat'],
                'area_center_lng' => $attributes['area_center_lng'],
                'area_radius_m' => $attributes['area_radius_m'],
                'updated_by_membership_id' => $actor->id,
                'activated_live_thread_id' => null,
            ]);
            $schedule->save();

            return $schedule->fresh();
        });
    }

    public function startThread(
        Space $space,
        SpaceMembership $actor,
        float $currentLat,
        float $currentLng,
    ): LiveThread {
        return $this->db->transaction(function () use ($space, $actor, $currentLat, $currentLng): LiveThread {
            $existing = $this->activeThreadForSpace($space);
            if ($existing) {
                throw new ApiException('LIVE_THREAD_ALREADY_ACTIVE', 'ライブスレッドはすでに開始されています。', 409);
            }

            $schedule = LiveThreadSchedule::query()
                ->where('space_id', $space->id)
                ->lockForUpdate()
                ->latest('updated_at')
                ->latest('id')
                ->first();

            if (! $schedule) {
                throw new ApiException('LIVE_THREAD_SCHEDULE_NOT_FOUND', 'ライブスレッド開始条件が設定されていません。', 409);
            }

            $schedule = $this->refreshScheduleStatus($schedule);

            if ($schedule->status === 'expired' || $this->windowExpired($schedule)) {
                throw new ApiException('LIVE_THREAD_WINDOW_EXPIRED', '開始可能時間を過ぎたためライブスレッドを開始できません。', 409);
            }

            if ($this->windowNotOpenedYet($schedule)) {
                throw new ApiException('LIVE_THREAD_WINDOW_NOT_OPEN', '開始可能時間前のためライブスレッドを開始できません。', 409);
            }

            $distanceMeters = $this->distanceMeters(
                $schedule->area_center_lat,
                $schedule->area_center_lng,
                $currentLat,
                $currentLng,
            );

            if ($distanceMeters > $schedule->area_radius_m) {
                throw new ApiException(
                    'LIVE_THREAD_OUT_OF_AREA',
                    '開始エリア外のためライブスレッドを開始できません。',
                    409,
                    [
                        'distanceMeters' => round($distanceMeters, 1),
                        'allowedRadiusM' => $schedule->area_radius_m,
                    ],
                );
            }

            $thread = LiveThread::query()->create([
                'space_id' => $space->id,
                'status' => 'active',
                'created_by_membership_id' => $actor->id,
                'starts_at' => now(),
            ]);

            $schedule->forceFill([
                'status' => 'started',
                'updated_by_membership_id' => $actor->id,
                'activated_live_thread_id' => $thread->id,
            ])->save();

            return $thread->fresh();
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
                $stream = $this->endActiveStream(
                    $stream,
                    $actor,
                    $systemAdmin,
                    $reason === 'force_closed' ? 'force_ended' : 'thread_closed',
                );
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
            'liveThreadSchedules' => fn ($scheduleQuery) => $scheduleQuery->latest('updated_at')->latest('id'),
            'liveThreads' => fn ($threadQuery) => $threadQuery->latest('starts_at')->latest('id'),
            'liveStreamSessions' => fn ($streamQuery) => $streamQuery->latest('started_at')->latest('id'),
        ]);

        if ($status === 'active') {
            $query->where(function ($spaceQuery): void {
                $spaceQuery
                    ->whereHas('liveThreads', fn ($threadQuery) => $threadQuery->where('status', 'active'))
                    ->orWhereHas('liveStreamSessions', fn ($streamQuery) => $streamQuery->where('status', 'live'));
            });
        } elseif ($status === 'scheduled') {
            $query->whereHas('liveThreadSchedules', fn ($scheduleQuery) => $scheduleQuery->where('status', 'scheduled'));
        } else {
            $query->where(function ($spaceQuery): void {
                $spaceQuery
                    ->whereHas('liveThreadSchedules')
                    ->orWhereHas('liveThreads')
                    ->orWhereHas('liveStreamSessions');
            });
        }

        return $query->orderByDesc('created_at')->limit($limit)->get()->map(function (Space $space): array {
            $schedule = $space->liveThreadSchedules->first();
            if ($schedule instanceof LiveThreadSchedule) {
                $schedule = $this->refreshScheduleStatus($schedule);
            }

            return [
                'space' => $space,
                'scheduledThread' => $schedule,
                'liveThread' => $space->liveThreads->firstWhere('status', 'active'),
                'liveStream' => $space->liveStreamSessions->firstWhere('status', 'live'),
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

    private function refreshScheduleStatus(LiveThreadSchedule $schedule): LiveThreadSchedule
    {
        if ($schedule->status === 'scheduled' && $this->windowExpired($schedule)) {
            $schedule->forceFill(['status' => 'expired'])->save();
            return $schedule->fresh();
        }

        return $schedule;
    }

    private function windowOpen(LiveThreadSchedule $schedule): bool
    {
        return ! $this->windowNotOpenedYet($schedule) && ! $this->windowExpired($schedule);
    }

    private function windowNotOpenedYet(LiveThreadSchedule $schedule): bool
    {
        return now()->lt($schedule->starts_at);
    }

    private function windowExpired(LiveThreadSchedule $schedule): bool
    {
        return now()->gte($schedule->ends_at);
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $sinLat = sin($latDelta / 2);
        $sinLng = sin($lngDelta / 2);
        $a = $sinLat * $sinLat
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * $sinLng * $sinLng;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
