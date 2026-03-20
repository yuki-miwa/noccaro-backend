<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpacePost;
use App\Models\SpacePostDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_me_endpoints_work(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'displayName' => 'New User',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'new-user@example.com')
            ->assertJsonPath('data.user.displayName', 'New User');

        $token = $response->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'new-user@example.com')
            ->assertJsonPath('data.notificationSettings.enabled', true);
    }

    public function test_join_returns_pending_for_approval_required_space(): void
    {
        $this->seed();
        $user = User::factory()->create([
            'email' => 'joiner@example.com',
            'display_name' => 'Joiner',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/spaces/join', [
            'spaceCode' => 'NOC2026',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.space.code', 'NOC2026')
            ->assertJsonPath('data.membership.status', 'pending');

        $this->getJson('/api/v1/spaces/joined')
            ->assertOk()
            ->assertJsonCount(1, 'data.joinedSpaces');
    }

    public function test_active_member_can_view_posts_and_toggle_reaction(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();

        Sanctum::actingAs($user);

        $postsResponse = $this->getJson('/api/v1/spaces/'.$space->public_id.'/posts');
        $postId = $postsResponse->json('data.0.id');

        $postsResponse
            ->assertOk()
            ->assertJsonPath('data.0.title', '最初のお知らせ')
            ->assertJsonPath('data.0.category', 'owner')
            ->assertJsonPath('data.0.audienceType', 'all_members')
            ->assertJsonPath('data.0.isRead', false)
            ->assertJsonPath('data.0.targetedToMe', false)
            ->assertJsonPath('data.0.reactedByMe', false);

        $this->putJson('/api/v1/posts/'.$postId.'/reaction', [
            'reactionType' => 'like',
            'enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.reactedByMe', true)
            ->assertJsonPath('data.reactionCount', 1);
    }

    public function test_posts_support_category_filters_targeting_and_read_state(): void
    {
        $this->seed();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $ownerMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $operationPost = SpacePost::query()->create([
            'space_id' => $space->id,
            'category' => 'operation',
            'audience_type' => 'all_members',
            'author_membership_id' => $ownerMembership->id,
            'title' => '運営からのお知らせ',
            'body' => 'operation category の確認用です。',
            'status' => 'published',
            'published_at' => now()->subMinutes(10),
        ]);

        $targetedOwnerPost = SpacePost::query()->create([
            'space_id' => $space->id,
            'category' => 'owner',
            'audience_type' => 'targeted_users',
            'author_membership_id' => $ownerMembership->id,
            'title' => 'あなた宛のお知らせ',
            'body' => 'targeted owner の確認用です。',
            'status' => 'published',
            'published_at' => now()->subMinutes(5),
        ]);

        SpacePostDelivery::query()->create([
            'post_id' => $targetedOwnerPost->id,
            'recipient_user_id' => $guest->id,
        ]);

        Sanctum::actingAs($guest);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $targetedOwnerPost->public_id)
            ->assertJsonPath('data.0.category', 'owner')
            ->assertJsonPath('data.0.audienceType', 'targeted_users')
            ->assertJsonPath('data.0.targetedToMe', true)
            ->assertJsonPath('data.0.isRead', false)
            ->assertJsonPath('data.0.readAt', null);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/posts?category=operation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $operationPost->public_id)
            ->assertJsonPath('data.0.category', 'operation')
            ->assertJsonPath('data.0.targetedToMe', false);

        $this->getJson('/api/v1/spaces/'.$space->public_id.'/posts?category=personal')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $detail = $this->getJson('/api/v1/posts/'.$targetedOwnerPost->public_id)
            ->assertOk()
            ->assertJsonPath('data.post.category', 'owner')
            ->assertJsonPath('data.post.audienceType', 'targeted_users')
            ->assertJsonPath('data.post.targetedToMe', true)
            ->assertJsonPath('data.post.isRead', false)
            ->assertJsonPath('data.post.readAt', null);

        $firstRead = $this->postJson('/api/v1/posts/'.$targetedOwnerPost->public_id.'/read')
            ->assertOk()
            ->assertJsonPath('data.postId', $targetedOwnerPost->public_id)
            ->assertJsonPath('data.isRead', true);

        $readAt = $firstRead->json('data.readAt');

        $this->postJson('/api/v1/posts/'.$targetedOwnerPost->public_id.'/read')
            ->assertOk()
            ->assertJsonPath('data.isRead', true)
            ->assertJsonPath('data.readAt', $readAt);

        $this->getJson('/api/v1/posts/'.$targetedOwnerPost->public_id)
            ->assertOk()
            ->assertJsonPath('data.post.isRead', true)
            ->assertJsonPath('data.post.readAt', $readAt);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/posts/'.$targetedOwnerPost->public_id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_active_member_can_report_whisper_and_auto_hide_at_threshold(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $space->update(['whisper_auto_hide_report_threshold' => 1]);

        Sanctum::actingAs($user);

        $whisperResponse = $this->getJson('/api/v1/spaces/'.$space->public_id.'/whispers');
        $whisperId = $whisperResponse->json('data.0.id');

        $this->postJson('/api/v1/whispers/'.$whisperId.'/report', [
            'reasonType' => 'privacy_risk',
            'detail' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.whisper.status', 'hidden_by_report')
            ->assertJsonPath('data.whisper.reportCount', 1);
    }

    public function test_notification_settings_and_device_registration_work(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/notification-settings', [
            'enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->postJson('/api/v1/devices/register', [
            'platform' => 'ios',
            'pushToken' => 'token-123',
            'deviceUuid' => 'device-1',
            'appVersion' => '1.0.0',
            'osVersion' => 'iOS 18',
        ])
            ->assertOk()
            ->assertJsonPath('data.device.pushToken', 'token-123')
            ->assertJsonPath('data.device.isActive', true);

        $this->postJson('/api/v1/devices/unregister', [
            'pushToken' => 'token-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.unregistered', true);
    }
}
