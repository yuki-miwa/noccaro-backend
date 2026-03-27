<?php

namespace Tests\Feature;

use App\Contracts\Live\LiveChatTokenIssuer;
use App\Models\LiveStreamSession;
use App\Models\LiveThread;
use App\Models\LiveThreadSchedule;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\User;
use App\Support\Live\IssuedLiveChatToken;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveThreadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('live.channel_arn', 'arn:aws:ivs:ap-northeast-1:328125385782:channel/CUkr1ExhTsHh');
        config()->set('live.playback_url', 'https://example.test/live.m3u8');
        config()->set('live.ingest_endpoint', 'rtmps://example.test/app/');
        config()->set('live.stream_key', 'test-stream-key');
        config()->set('live.chat.room_arn', 'arn:aws:ivschat:ap-northeast-1:328125385782:room/XOwd5szNiUaJ');
        config()->set('live.chat.room_id', 'XOwd5szNiUaJ');
        config()->set('live.chat.endpoint', 'wss://edge.ivschat.ap-northeast-1.amazonaws.com');
        config()->set('live.chat.session_duration_minutes', 60);
        config()->set('live.chat.message_max_length', 30);
        config()->set('live.chat.cooldown_seconds', 3);

        $this->app->bind(LiveChatTokenIssuer::class, fn () => new class implements LiveChatTokenIssuer
        {
            public function configured(): bool
            {
                return true;
            }

            public function issue(
                string $userId,
                array $attributes = [],
                array $capabilities = ['SEND_MESSAGE'],
                ?int $sessionDurationMinutes = null,
            ): IssuedLiveChatToken {
                return new IssuedLiveChatToken(
                    token: 'chat-token-for-'.$userId,
                    tokenExpiresAt: new DateTimeImmutable('2026-03-26T21:00:00+09:00'),
                    sessionExpiresAt: new DateTimeImmutable('2026-03-26T22:00:00+09:00'),
                    roomArn: 'arn:aws:ivschat:ap-northeast-1:328125385782:room/XOwd5szNiUaJ',
                    roomId: 'XOwd5szNiUaJ',
                    endpoint: 'wss://edge.ivschat.ap-northeast-1.amazonaws.com',
                );
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_live_thread_auto_starts_and_in_area_members_can_watch_and_comment(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $pending = User::query()->where('email', 'pending@noccaro.local')->firstOrFail();
        $ownerMembership = $this->ownerMembershipForSpace($space, $owner);

        $this->createOpenSchedule($space, $ownerMembership);
        Artisan::call('noccaro:sync-live-threads');

        Sanctum::actingAs($guest);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread?currentLat=35.6800&currentLng=139.7670')
            ->assertOk()
            ->assertJsonPath('data.scheduledThread.status', 'started')
            ->assertJsonPath('data.liveThread.status', 'active')
            ->assertJsonPath('data.liveStream.status', 'idle')
            ->assertJsonPath('data.permissions.canWatch', true)
            ->assertJsonPath('data.permissions.canComment', true)
            ->assertJsonPath('data.permissions.canStartThread', false)
            ->assertJsonPath('data.permissions.canStartStream', false)
            ->assertJsonPath('data.eligibility.canAccessLiveNow', true)
            ->assertJsonPath('data.eligibility.insideAudienceArea', true)
            ->assertJsonPath('data.eligibility.windowOpen', true)
            ->assertJsonPath('data.eligibility.reasonCode', null);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread?currentLat=35.6900&currentLng=139.7800')
            ->assertOk()
            ->assertJsonPath('data.permissions.canWatch', false)
            ->assertJsonPath('data.permissions.canComment', false)
            ->assertJsonPath('data.eligibility.insideAudienceArea', false)
            ->assertJsonPath('data.eligibility.reasonCode', 'LIVE_THREAD_OUT_OF_AREA')
            ->assertJsonPath('data.liveStream.playbackUrl', null);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-chat/token', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])
            ->assertOk()
            ->assertJsonPath('data.chat.roomId', 'XOwd5szNiUaJ')
            ->assertJsonPath('data.chat.token', fn ($value) => str_starts_with($value, 'chat-token-for-member-'))
            ->assertJsonPath('data.chat.messageMaxLength', 30)
            ->assertJsonPath('data.chat.cooldownSeconds', 3);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-chat/token', [
            'currentLat' => 35.6900,
            'currentLng' => 139.7800,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIVE_THREAD_OUT_OF_AREA');

        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread?currentLat=35.6800&currentLng=139.7670')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-chat/token', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])->assertStatus(403);
    }

    public function test_primary_owner_must_be_in_geofence_to_start_stream(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $ownerMembership = $this->ownerMembershipForSpace($space, $owner);

        $this->createOpenSchedule($space, $ownerMembership);
        Artisan::call('noccaro:sync-live-threads');

        Sanctum::actingAs($guest);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start', [
            'currentLat' => 35.6900,
            'currentLng' => 139.7800,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIVE_THREAD_OUT_OF_AREA');

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'active')
            ->assertJsonPath('data.liveStream.status', 'live')
            ->assertJsonPath('data.liveStream.isLive', true)
            ->assertJsonPath('data.broadcast.streamKey', 'test-stream-key')
            ->assertJsonPath('data.permissions.canStartStream', false)
            ->assertJsonPath('data.permissions.canEndStream', true);
    }

    public function test_scheduler_auto_closes_thread_and_stream_when_window_expires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 19:05:00', 'Asia/Tokyo'));

        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $ownerMembership = $this->ownerMembershipForSpace($space, $owner);

        LiveThreadSchedule::query()->create([
            'space_id' => $space->id,
            'status' => 'scheduled',
            'starts_at' => Carbon::now()->subMinutes(5),
            'ends_at' => Carbon::now()->addMinutes(5),
            'area_center_lat' => 35.6800,
            'area_center_lng' => 139.7670,
            'area_radius_m' => 150,
            'created_by_membership_id' => $ownerMembership->id,
            'updated_by_membership_id' => $ownerMembership->id,
        ]);

        Artisan::call('noccaro:sync-live-threads');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-03-27 19:11:00', 'Asia/Tokyo'));
        Artisan::call('noccaro:sync-live-threads');

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread?currentLat=35.6800&currentLng=139.7670')
            ->assertOk()
            ->assertJsonPath('data.scheduledThread.status', 'expired')
            ->assertJsonPath('data.liveThread', null)
            ->assertJsonPath('data.liveStream.status', 'idle')
            ->assertJsonPath('data.permissions.canWatch', false)
            ->assertJsonPath('data.permissions.canComment', false)
            ->assertJsonPath('data.eligibility.windowOpen', false)
            ->assertJsonPath('data.eligibility.reasonCode', 'LIVE_THREAD_WINDOW_EXPIRED');

        $this->assertDatabaseHas('live_threads', [
            'space_id' => $space->id,
            'status' => 'closed',
            'close_reason' => 'schedule_ended',
        ]);
        $this->assertDatabaseHas('live_stream_sessions', [
            'space_id' => $space->id,
            'status' => 'ended',
            'end_reason' => 'schedule_ended',
        ]);
        $this->assertDatabaseHas('live_thread_schedules', [
            'space_id' => $space->id,
            'status' => 'expired',
        ]);
    }

    public function test_manual_thread_start_and_close_routes_are_disabled(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/close')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_system_admin_can_monitor_and_force_stop_auto_started_live(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $systemAdminUser = User::query()->where('email', 'sysadmin@noccaro.local')->firstOrFail();
        $ownerMembership = $this->ownerMembershipForSpace($space, $owner);

        $schedule = $this->createOpenSchedule($space, $ownerMembership);
        Artisan::call('noccaro:sync-live-threads');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start', [
            'currentLat' => 35.6800,
            'currentLng' => 139.7670,
        ])->assertOk();

        Sanctum::actingAs($systemAdminUser);
        $this->getJson('/api/v1/system-admin/live-threads?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space.code', 'NOC2026')
            ->assertJsonPath('data.0.scheduledThread.id', $schedule->public_id)
            ->assertJsonPath('data.0.scheduledThread.status', 'started')
            ->assertJsonPath('data.0.liveThread.status', 'active')
            ->assertJsonPath('data.0.liveStream.status', 'live');

        $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/live-stream/force-end')
            ->assertOk()
            ->assertJsonPath('data.liveStream.status', 'ended');

        $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/live-thread/force-close')
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'closed');
    }

    private function ownerMembershipForSpace(Space $space, User $owner): SpaceMembership
    {
        return SpaceMembership::query()
            ->where('space_id', $space->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();
    }

    private function createOpenSchedule(Space $space, SpaceMembership $ownerMembership): LiveThreadSchedule
    {
        return LiveThreadSchedule::query()->create([
            'space_id' => $space->id,
            'status' => 'scheduled',
            'starts_at' => Carbon::now()->subMinutes(5),
            'ends_at' => Carbon::now()->addHour(),
            'area_center_lat' => 35.6800,
            'area_center_lng' => 139.7670,
            'area_radius_m' => 150,
            'created_by_membership_id' => $ownerMembership->id,
            'updated_by_membership_id' => $ownerMembership->id,
        ]);
    }
}
