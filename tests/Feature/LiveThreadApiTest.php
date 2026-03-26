<?php

namespace Tests\Feature;

use App\Contracts\Live\LiveChatTokenIssuer;
use App\Models\Space;
use App\Models\User;
use App\Support\Live\IssuedLiveChatToken;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_primary_owner_can_start_thread_and_stream_and_active_guest_can_get_chat_token(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread')
            ->assertOk()
            ->assertJsonPath('data.liveThread', null)
            ->assertJsonPath('data.liveStream.status', 'idle')
            ->assertJsonPath('data.permissions.canStartThread', true)
            ->assertJsonPath('data.permissions.canStartStream', false);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start')
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'active')
            ->assertJsonPath('data.permissions.canCloseThread', true)
            ->assertJsonPath('data.permissions.canStartStream', true);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start')
            ->assertOk()
            ->assertJsonPath('data.liveStream.status', 'live')
            ->assertJsonPath('data.liveStream.isLive', true)
            ->assertJsonPath('data.liveStream.playbackUrl', 'https://example.test/live.m3u8')
            ->assertJsonPath('data.broadcast.streamKey', 'test-stream-key')
            ->assertJsonPath('data.broadcast.ingestEndpoint', 'rtmps://example.test/app/');

        Sanctum::actingAs($guest);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread')
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'active')
            ->assertJsonPath('data.permissions.canWatch', true)
            ->assertJsonPath('data.permissions.canComment', true)
            ->assertJsonPath('data.permissions.canStartStream', false);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-chat/token')
            ->assertOk()
            ->assertJsonPath('data.chat.roomId', 'XOwd5szNiUaJ')
            ->assertJsonPath('data.chat.endpoint', 'wss://edge.ivschat.ap-northeast-1.amazonaws.com')
            ->assertJsonPath('data.chat.token', fn ($value) => str_starts_with($value, 'chat-token-for-member-'))
            ->assertJsonPath('data.chat.messageMaxLength', 30)
            ->assertJsonPath('data.chat.cooldownSeconds', 3);
    }

    public function test_non_primary_or_non_active_members_cannot_start_or_watch_live(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $pending = User::query()->where('email', 'pending@noccaro.local')->firstOrFail();

        Sanctum::actingAs($guest);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start')->assertOk();

        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/spaces/'.$space->public_id.'/live-thread')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-chat/token')
            ->assertStatus(403);
    }

    public function test_primary_owner_can_end_stream_and_close_thread(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start')->assertOk();
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start')->assertOk();

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/end')
            ->assertOk()
            ->assertJsonPath('data.liveStream.status', 'ended')
            ->assertJsonPath('data.liveStream.isLive', false)
            ->assertJsonPath('data.permissions.canStartStream', true);

        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/close')
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'closed')
            ->assertJsonPath('data.permissions.canStartThread', true)
            ->assertJsonPath('data.liveStream.status', 'idle');
    }

    public function test_system_admin_can_list_active_live_threads_and_force_stop_them(): void
    {
        $this->seed();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $systemAdminUser = User::query()->where('email', 'sysadmin@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-thread/start')->assertOk();
        $this->postJson('/api/v1/spaces/'.$space->public_id.'/live-stream/start')->assertOk();

        Sanctum::actingAs($systemAdminUser);

        $this->getJson('/api/v1/system-admin/live-threads?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space.code', 'NOC2026')
            ->assertJsonPath('data.0.liveThread.status', 'active')
            ->assertJsonPath('data.0.liveStream.status', 'live');

        $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/live-stream/force-end')
            ->assertOk()
            ->assertJsonPath('data.liveStream.status', 'ended');

        $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/live-thread/force-close')
            ->assertOk()
            ->assertJsonPath('data.liveThread.status', 'closed');

        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'live_stream_force_ended',
            'entity_public_id' => $space->public_id,
        ]);
        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'live_thread_force_closed',
            'entity_public_id' => $space->public_id,
        ]);
    }
}
