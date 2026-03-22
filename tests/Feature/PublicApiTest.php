<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\SpaceCreationRequest;
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
            ->assertJsonPath('data.notificationSettings.enabled', true)
            ->assertJsonPath('data.profile.pendingEmail', null);
    }

    public function test_user_can_update_display_name_and_email_profile_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'profile-user@example.com',
            'display_name' => 'Before Update',
            'password' => 'password123',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me/profile', [
            'displayName' => 'After Update',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.displayName', 'After Update')
            ->assertJsonPath('data.user.email', 'profile-user@example.com')
            ->assertJsonPath('data.profileUpdate.emailChangeRequiresVerification', false)
            ->assertJsonPath('data.profileUpdate.pendingEmail', null);

        $this->patchJson('/api/v1/me/profile', [
            'displayName' => 'After Email Update',
            'email' => 'profile-updated@example.com',
            'currentPassword' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.displayName', 'After Email Update')
            ->assertJsonPath('data.user.email', 'profile-updated@example.com')
            ->assertJsonPath('data.profileUpdate.emailChangeRequiresVerification', false)
            ->assertJsonPath('data.profileUpdate.pendingEmail', null);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.displayName', 'After Email Update')
            ->assertJsonPath('data.user.email', 'profile-updated@example.com')
            ->assertJsonPath('data.profile.pendingEmail', null);
    }

    public function test_profile_email_change_validates_current_password_and_uniqueness(): void
    {
        $user = User::factory()->create([
            'email' => 'profile-check@example.com',
            'display_name' => 'Profile Check',
            'password' => 'password123',
            'status' => 'active',
        ]);
        User::factory()->create([
            'email' => 'already-used@example.com',
            'display_name' => 'Already Used',
            'password' => 'password123',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me/profile', [
            'email' => 'next@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.field', 'currentPassword');

        $this->patchJson('/api/v1/me/profile', [
            'email' => 'next@example.com',
            'currentPassword' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRENT_PASSWORD_INVALID');

        $this->patchJson('/api/v1/me/profile', [
            'email' => 'already-used@example.com',
            'currentPassword' => 'password123',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_ALREADY_TAKEN');
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

    public function test_user_can_create_and_list_space_creation_requests(): void
    {
        $this->seed();
        $user = User::factory()->create([
            'email' => 'creator@example.com',
            'display_name' => 'Creator',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/spaces/creation-requests', [
            'spaceName' => 'Noccaro Osaka',
            'spaceCode' => 'osaka-new',
            'joinPolicy' => 'approval_required',
        ])
            ->assertCreated()
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonPath('data.request.requestType', 'space_creation')
            ->assertJsonPath('data.request.spaceCode', 'OSAKA-NEW');

        $requestId = $created->json('data.request.id');

        $this->getJson('/api/v1/spaces/creation-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.spaceName', 'Noccaro Osaka');

        $this->getJson('/api/v1/spaces/creation-requests/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonPath('data.request.createdSpaceId', null);

        $this->postJson('/api/v1/spaces/creation-requests', [
            'spaceName' => 'Another Space',
            'spaceCode' => 'ANOTHER1',
            'joinPolicy' => 'auto_approve',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SPACE_CREATION_REQUEST_ALREADY_PENDING');

        Sanctum::actingAs(User::factory()->create([
            'email' => 'second-creator@example.com',
            'display_name' => 'Second Creator',
            'status' => 'active',
        ]));

        $this->postJson('/api/v1/spaces/creation-requests', [
            'spaceName' => 'Reserved code test',
            'spaceCode' => 'osaka-new',
            'joinPolicy' => 'approval_required',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SPACE_CODE_ALREADY_RESERVED');
    }

    public function test_public_creation_request_visibility_matches_pending_rejected_and_approved_rules(): void
    {
        $this->seed();
        $user = User::factory()->create([
            'email' => 'creation-state@example.com',
            'display_name' => 'Creation State',
            'status' => 'active',
        ]);
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();

        $pending = SpaceCreationRequest::query()->create([
            'requester_user_id' => $user->id,
            'future_primary_owner_user_id' => $user->id,
            'requested_space_name' => 'Pending Space',
            'requested_space_code' => 'PENDING01',
            'requested_join_policy' => 'approval_required',
            'status' => 'pending',
        ]);

        $visibleRejected = SpaceCreationRequest::query()->create([
            'requester_user_id' => $user->id,
            'future_primary_owner_user_id' => $user->id,
            'requested_space_name' => 'Rejected Visible',
            'requested_space_code' => 'REJECT01',
            'requested_join_policy' => 'auto_approve',
            'status' => 'rejected',
            'rejected_at' => now()->subHours(12),
            'reviewed_at' => now()->subHours(12),
            'rejection_visible_until' => now()->addHours(60),
        ]);

        $expiredRejected = SpaceCreationRequest::query()->create([
            'requester_user_id' => $user->id,
            'future_primary_owner_user_id' => $user->id,
            'requested_space_name' => 'Rejected Hidden',
            'requested_space_code' => 'REJECT02',
            'requested_join_policy' => 'auto_approve',
            'status' => 'rejected',
            'rejected_at' => now()->subHours(80),
            'reviewed_at' => now()->subHours(80),
            'rejection_visible_until' => now()->subHours(8),
        ]);

        $approved = SpaceCreationRequest::query()->create([
            'requester_user_id' => $user->id,
            'future_primary_owner_user_id' => $user->id,
            'requested_space_name' => 'Approved Space',
            'requested_space_code' => 'APPROVED1',
            'requested_join_policy' => 'auto_approve',
            'status' => 'approved',
            'approved_space_id' => $space->id,
            'approved_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/spaces/creation-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $pending->public_id,
                'status' => 'pending',
            ])
            ->assertJsonFragment([
                'id' => $visibleRejected->public_id,
                'status' => 'rejected',
            ]);

        $this->getJson('/api/v1/spaces/creation-requests/'.$approved->public_id)
            ->assertOk()
            ->assertJsonPath('data.request.status', 'approved')
            ->assertJsonPath('data.request.createdSpaceId', $space->public_id);

        $this->getJson('/api/v1/spaces/creation-requests/'.$expiredRejected->public_id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SPACE_CREATION_REQUEST_NOT_FOUND');
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
