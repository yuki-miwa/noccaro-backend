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
        $syncedState = $this->synchronizeSpace($space);
        $schedule = $syncedState['scheduledThread'];
        $thread = $syncedState['liveThread'];
        $stream = $syncedState['liveStream'];
        $eligibility = $this->eligibilityForMembership($membership, $schedule, $thread, $currentLat, $currentLng);

        return [
            'scheduledThread' => $schedule,
            'liveThread' => $thread,
            'liveStream' => $stream,
            'permissions' => $this->permissionsForMembership($membership, $thread, $stream, $eligibility),
            'eligibility' => $eligibility,
        ];
    }

    public function permissionsForMembership(
        SpaceMembership $membership,
        ?LiveThread $thread,
        ?LiveStreamSession $stream,
        array $eligibility,
    ): array {
        $isPrimaryOwner = $membership->status === 'active' && $membership->role === 'primary_owner';
        $hasActiveThread = $thread?->status === 'active';
        $hasLiveStream = $stream?->status === 'live';
        $canAccessLiveNow = (bool) ($eligibility['canAccessLiveNow'] ?? false);

        return [
            'canWatch' => $membership->status === 'active' && $hasActiveThread && $canAccessLiveNow,
            'canComment' => $membership->status === 'active' && $hasActiveThread && $canAccessLiveNow,
            'canStartThread' => false,
            'canCloseThread' => false,
            'canStartStream' => $isPrimaryOwner && $hasActiveThread && ! $hasLiveStream && $canAccessLiveNow,
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
        $threadActive = $thread?->status === 'active';
        $windowOpen = $schedule ? $this->windowOpen($schedule) : false;
        $distanceMeters = null;
        $insideArea = null;
        $reasonCode = null;
        $allowedRadiusM = $schedule?->area_radius_m;

        if ($membership->status !== 'active') {
            $reasonCode = 'FORBIDDEN';
        } elseif (! $schedule) {
            $reasonCode = 'LIVE_THREAD_SCHEDULE_NOT_FOUND';
        } elseif ($schedule->status === 'cancelled') {
            $reasonCode = 'LIVE_THREAD_NOT_ACTIVE';
        } elseif ($this->windowNotOpenedYet($schedule)) {
            $reasonCode = 'LIVE_THREAD_WINDOW_NOT_OPEN';
        } elseif ($this->windowExpired($schedule) || $schedule->status === 'expired') {
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
            $insideArea = $distanceMeters <= $schedule->area_radius_m;
            if (! $insideArea) {
                $reasonCode = 'LIVE_THREAD_OUT_OF_AREA';
            } elseif (! $threadActive) {
                $reasonCode = 'LIVE_THREAD_NOT_ACTIVE';
            }
        } elseif (! $threadActive) {
            $reasonCode = 'LIVE_THREAD_NOT_ACTIVE';
        }

        return [
            'canStartThreadNow' => false,
            'canAccessLiveNow' => $reasonCode === null && $threadActive && $insideArea === true,
            'insideStartArea' => $insideArea,
            'insideLiveArea' => $insideArea,
            'insideAudienceArea' => $insideArea,
            'distanceMeters' => $distanceMeters,
            'allowedRadiusM' => $allowedRadiusM,
            'windowOpen' => $windowOpen,
            'threadActive' => $threadActive,
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
            if ($this->activeThreadForSpace($space)) {
                throw new ApiException('LIVE_THREAD_ALREADY_ACTIVE', 'ライブスレッド稼働中は予約を更新できません。', 409);
            }

            $schedule = LiveThreadSchedule::query()->lockForUpdate()->firstOrNew(['space_id' => $space->id]);

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

    public function cancelSchedule(Space $space, SpaceMembership $actor): LiveThreadSchedule
    {
        return $this->db->transaction(function () use ($space, $actor): LiveThreadSchedule {
            if ($this->activeThreadForSpace($space)) {
                throw new ApiException('LIVE_THREAD_ALREADY_ACTIVE', 'ライブスレッド稼働中は予約を取り消せません。', 409);
            }

            $schedule = LiveThreadSchedule::query()
                ->where('space_id', $space->id)
                ->lockForUpdate()
                ->latest('updated_at')
                ->latest('id')
                ->first();

            if (! $schedule) {
                throw new ApiException('LIVE_THREAD_SCHEDULE_NOT_FOUND', 'ライブスレッド予約が設定されていません。', 404);
            }

            $schedule->forceFill([
                'status' => 'cancelled',
                'updated_by_membership_id' => $actor->id,
                'activated_live_thread_id' => null,
            ])->save();

            return $schedule->fresh();
        });
    }

    public function startThread(
        Space $space,
        SpaceMembership $actor,
        float $currentLat,
        float $currentLng,
    ): LiveThread {
        throw new ApiException('FORBIDDEN', 'ライブスレッドは予約時刻で自動開始されます。', 403);
    }

    public function closeThread(
        Space $space,
        ?SpaceMembership $actor = null,
        ?SystemAdmin $systemAdmin = null,
        string $reason = 'closed',
    ): array {
        return $this->db->transaction(function () use ($space, $actor, $reason, $systemAdmin): array {
            $thread = LiveThread::query()
                ->where('space_id', $space->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('starts_at')
                ->latest('id')
                ->first();
            $stream = LiveStreamSession::query()
                ->where('space_id', $space->id)
                ->where('status', 'live')
                ->lockForUpdate()
                ->latest('started_at')
                ->latest('id')
                ->first();

            if ($stream) {
                $stream = $this->endActiveStream(
                    $stream,
                    $actor,
                    $systemAdmin,
                    in_array($reason, ['force_closed', 'schedule_ended'], true)
                        ? ($reason === 'force_closed' ? 'force_ended' : 'schedule_ended')
                        : 'thread_closed',
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

            if ($reason === 'schedule_ended') {
                LiveThreadSchedule::query()
                    ->where('space_id', $space->id)
                    ->where('status', 'started')
                    ->latest('updated_at')
                    ->latest('id')
                    ->limit(1)
                    ->update(['status' => 'expired']);
            }

            return [
                'liveThread' => $thread->fresh(),
                'liveStream' => $stream,
            ];
        });
    }

    public function startStream(
        Space $space,
        SpaceMembership $actor,
        float $currentLat,
        float $currentLng,
    ): LiveStreamSession {
        return $this->db->transaction(function () use ($space, $actor, $currentLat, $currentLng): LiveStreamSession {
            $this->synchronizeSpace($space);

            $existing = LiveStreamSession::query()
                ->where('space_id', $space->id)
                ->where('status', 'live')
                ->lockForUpdate()
                ->latest('started_at')
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }

            $thread = LiveThread::query()
                ->where('space_id', $space->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('starts_at')
                ->latest('id')
                ->first();
            if (! $thread) {
                throw new ApiException('LIVE_THREAD_NOT_ACTIVE', 'ライブスレッドが開始されていません。', 409);
            }

            $schedule = LiveThreadSchedule::query()
                ->where('space_id', $space->id)
                ->latest('updated_at')
                ->latest('id')
                ->first();
            if (! $schedule) {
                throw new ApiException('LIVE_THREAD_SCHEDULE_NOT_FOUND', 'ライブスレッド開始条件が設定されていません。', 409);
            }

            if ($this->windowNotOpenedYet($schedule)) {
                throw new ApiException('LIVE_THREAD_WINDOW_NOT_OPEN', '開始可能時間前のため配信を開始できません。', 409);
            }

            if ($this->windowExpired($schedule) || $schedule->status === 'expired') {
                throw new ApiException('LIVE_THREAD_WINDOW_EXPIRED', '開始可能時間を過ぎたため配信を開始できません。', 409);
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
                    '開始エリア外のため配信を開始できません。',
                    409,
                    [
                        'distanceMeters' => round($distanceMeters, 1),
                        'allowedRadiusM' => $schedule->area_radius_m,
                    ],
                );
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
            $stream = LiveStreamSession::query()
                ->where('space_id', $space->id)
                ->where('status', 'live')
                ->lockForUpdate()
                ->latest('started_at')
                ->latest('id')
                ->first();
            if (! $stream) {
                return null;
            }

            return $this->endActiveStream($stream, $actor, $systemAdmin, $reason);
        });
    }

    public function activeSummaries(int $limit = 50, string $status = 'active'): Collection
    {
        $this->synchronizeDueSchedules();

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
            return [
                'space' => $space,
                'scheduledThread' => $space->liveThreadSchedules->first(),
                'liveThread' => $space->liveThreads->firstWhere('status', 'active'),
                'liveStream' => $space->liveStreamSessions->firstWhere('status', 'live'),
            ];
        });
    }

    public function synchronizeDueSchedules(): array
    {
        $spaceIds = LiveThreadSchedule::query()
            ->whereIn('status', ['scheduled', 'started'])
            ->pluck('space_id')
            ->merge(
                LiveThread::query()
                    ->where('status', 'active')
                    ->pluck('space_id')
            )
            ->unique()
            ->values();

        $started = 0;
        $closed = 0;

        if ($spaceIds->isEmpty()) {
            return ['started' => 0, 'closed' => 0, 'checked' => 0];
        }

        Space::query()
            ->whereIn('id', $spaceIds)
            ->get()
            ->each(function (Space $space) use (&$started, &$closed): void {
                $result = $this->synchronizeSpace($space);
                if ($result['threadStarted']) {
                    $started++;
                }
                if ($result['threadClosed']) {
                    $closed++;
                }
            });

        return [
            'started' => $started,
            'closed' => $closed,
            'checked' => $spaceIds->count(),
        ];
    }

    public function synchronizeSpace(Space $space): array
    {
        return $this->db->transaction(function () use ($space): array {
            $schedule = LiveThreadSchedule::query()
                ->where('space_id', $space->id)
                ->lockForUpdate()
                ->latest('updated_at')
                ->latest('id')
                ->first();
            $thread = LiveThread::query()
                ->where('space_id', $space->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('starts_at')
                ->latest('id')
                ->first();
            $stream = LiveStreamSession::query()
                ->where('space_id', $space->id)
                ->where('status', 'live')
                ->lockForUpdate()
                ->latest('started_at')
                ->latest('id')
                ->first();

            $threadStarted = false;
            $threadClosed = false;

            if ($schedule) {
                if ($thread && ($schedule->status === 'cancelled' || $this->windowExpired($schedule))) {
                    if ($stream) {
                        $stream = $this->endActiveStream($stream, null, null, 'schedule_ended');
                    }

                    $thread->forceFill([
                        'status' => 'closed',
                        'closed_at' => now(),
                        'close_reason' => 'schedule_ended',
                    ])->save();

                    $thread = null;
                    $threadClosed = true;
                    if ($schedule->status !== 'cancelled') {
                        $schedule->forceFill(['status' => 'expired'])->save();
                    }
                }

                if (! $thread && $schedule->status === 'scheduled' && $this->windowOpen($schedule)) {
                    $thread = $this->createThreadFromSchedule($space, $schedule);
                    $schedule->forceFill([
                        'status' => 'started',
                        'activated_live_thread_id' => $thread->id,
                    ])->save();
                    $threadStarted = true;
                }

                if (! $thread && in_array($schedule->status, ['scheduled', 'started'], true) && $this->windowExpired($schedule)) {
                    $schedule->forceFill(['status' => 'expired'])->save();
                }
            }

            return [
                'scheduledThread' => $schedule?->fresh(),
                'liveThread' => $thread?->fresh(),
                'liveStream' => $stream?->fresh(),
                'threadStarted' => $threadStarted,
                'threadClosed' => $threadClosed,
            ];
        });
    }

    private function createThreadFromSchedule(Space $space, LiveThreadSchedule $schedule): LiveThread
    {
        $creatorMembershipId = $schedule->updated_by_membership_id ?? $schedule->created_by_membership_id;

        return LiveThread::query()->create([
            'space_id' => $space->id,
            'status' => 'active',
            'created_by_membership_id' => $creatorMembershipId,
            'starts_at' => now(),
        ]);
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
        if (in_array($schedule->status, ['scheduled', 'started'], true) && $this->windowExpired($schedule)) {
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
