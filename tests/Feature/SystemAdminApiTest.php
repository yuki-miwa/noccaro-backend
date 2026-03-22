<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceCreationRequest;
use App\Models\SpaceMembership;
use App\Models\SpacePost;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_login_and_view_dashboard(): void
    {
        $this->seed();

        $login = $this->postJson('/api/v1/system-admin/auth/login', [
            'email' => 'sysadmin@noccaro.local',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'system_admin');

        $token = $login->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/system-admin/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'sysadmin@noccaro.local');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/system-admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.spaceCount', 1)
            ->assertJsonPath('data.userCount', 4)
            ->assertJsonPath('data.orphanedPrimaryOwnerCount', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/system-admin/spaces?status=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.primaryOwner.email', 'owner@noccaro.local');
    }

    public function test_system_admin_can_manage_spaces_and_users(): void
    {
        $this->seed();

        $adminUser = $this->systemAdminUser();
        $initialOwner = User::factory()->create([
            'email' => 'new-primary@example.com',
            'display_name' => 'New Primary',
            'status' => 'active',
        ]);
        $backupOwner = User::factory()->create([
            'email' => 'backup-primary@example.com',
            'display_name' => 'Backup Primary',
            'status' => 'active',
        ]);

        Sanctum::actingAs($adminUser);

        $created = $this->postJson('/api/v1/system-admin/spaces', [
            'name' => '運営作成スペース',
            'description' => 'system admin が作成したスペース',
            'spaceCode' => 'OPS2026',
            'joinPolicy' => 'approval_required',
            'maxOwnerCount' => 3,
            'whisperTtlMinutes' => 180,
            'whisperMaxLength' => 30,
            'locationGridMeters' => 120,
            'locationJitterEnabled' => true,
            'initialPrimaryOwnerUserId' => $initialOwner->public_id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.space.code', 'OPS2026')
            ->assertJsonPath('data.primaryOwner.userId', $initialOwner->public_id);

        $spaceId = $created->json('data.space.id');

        Sanctum::actingAs($initialOwner);
        $this->getJson('/api/v1/spaces/'.$spaceId)
            ->assertOk()
            ->assertJsonPath('data.space.description', 'system admin が作成したスペース')
            ->assertJsonPath('data.space.maxOwnerCount', 3)
            ->assertJsonPath('data.space.whisperTtlMinutes', 180)
            ->assertJsonPath('data.space.whisperMaxLength', 30)
            ->assertJsonPath('data.space.locationGridMeters', 120)
            ->assertJsonPath('data.space.locationJitterEnabled', true)
            ->assertJsonPath('data.space.autoHideReportThreshold', 5)
            ->assertJsonPath('data.space.postLimitPerMinute', 1)
            ->assertJsonPath('data.space.postLimitPerTenMinutes', 3);

        Sanctum::actingAs($adminUser);

        $this->patchJson('/api/v1/system-admin/spaces/'.$spaceId, [
            'status' => 'suspended',
            'note' => 'support review',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->postJson('/api/v1/system-admin/spaces/'.$spaceId.'/primary-owner', [
            'userId' => $backupOwner->public_id,
            'note' => 'handover',
        ])
            ->assertOk()
            ->assertJsonPath('data.primaryOwner.userId', $backupOwner->public_id);

        $this->patchJson('/api/v1/system-admin/users/'.$initialOwner->public_id, [
            'status' => 'locked',
            'note' => 'manual review',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'locked');

        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'space_created',
            'entity_type' => 'space',
        ]);

        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'user_status_updated',
            'entity_type' => 'user',
            'entity_public_id' => $initialOwner->public_id,
        ]);

        SpaceCreationRequest::query()->create([
            'requester_user_id' => $backupOwner->id,
            'future_primary_owner_user_id' => $backupOwner->id,
            'requested_space_name' => '予約済みスペース',
            'requested_space_code' => 'RESERVED1',
            'requested_join_policy' => 'approval_required',
            'status' => 'pending',
        ]);

        $this->postJson('/api/v1/system-admin/spaces', [
            'name' => '衝突スペース',
            'description' => 'pending request と同じコード',
            'spaceCode' => 'reserved1',
            'joinPolicy' => 'approval_required',
            'maxOwnerCount' => 3,
            'whisperTtlMinutes' => 180,
            'whisperMaxLength' => 30,
            'locationGridMeters' => 120,
            'locationJitterEnabled' => true,
            'initialPrimaryOwnerUserId' => $backupOwner->public_id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SPACE_CODE_ALREADY_RESERVED');
    }

    public function test_system_admin_can_review_space_creation_requests(): void
    {
        $this->seed();

        $adminUser = $this->systemAdminUser();
        $requester = User::factory()->create([
            'email' => 'space-requester@example.com',
            'display_name' => 'Space Requester',
            'status' => 'active',
        ]);

        $pendingRequest = SpaceCreationRequest::query()->create([
            'requester_user_id' => $requester->id,
            'future_primary_owner_user_id' => $requester->id,
            'requested_space_name' => '新規承認スペース',
            'requested_space_code' => 'CREATE42',
            'requested_join_policy' => 'approval_required',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($adminUser);

        $this->getJson('/api/v1/system-admin/space-creation-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.request.id', $pendingRequest->public_id)
            ->assertJsonPath('data.0.requester.email', 'space-requester@example.com');

        $approved = $this->postJson('/api/v1/system-admin/space-creation-requests/'.$pendingRequest->public_id.'/approve', [
            'note' => 'initial approval',
        ])
            ->assertOk()
            ->assertJsonPath('data.request.request.status', 'approved')
            ->assertJsonPath('data.request.request.createdSpaceId', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.space.space.code', 'CREATE42')
            ->assertJsonPath('data.space.primaryOwner.userId', $requester->public_id);

        $createdSpaceId = $approved->json('data.space.space.id');

        $this->assertDatabaseHas('spaces', [
            'public_id' => $createdSpaceId,
            'space_code' => 'CREATE42',
            'max_owner_count' => 3,
            'whisper_ttl_minutes' => 180,
            'whisper_max_length' => 20,
            'location_grid_meters' => 120,
            'whisper_auto_hide_report_threshold' => 5,
            'whisper_rate_limit_per_minute' => 1,
            'whisper_rate_limit_per_10min' => 3,
            'location_jitter_enabled' => true,
            'created_by_user_id' => $requester->id,
        ]);

        $this->assertDatabaseHas('space_memberships', [
            'space_id' => Space::query()->where('public_id', $createdSpaceId)->firstOrFail()->id,
            'user_id' => $requester->id,
            'role' => 'primary_owner',
            'status' => 'active',
        ]);

        $rejectTarget = SpaceCreationRequest::query()->create([
            'requester_user_id' => $requester->id,
            'future_primary_owner_user_id' => $requester->id,
            'requested_space_name' => '棄却対象スペース',
            'requested_space_code' => 'REJECT42',
            'requested_join_policy' => 'auto_approve',
            'status' => 'pending',
        ]);

        $this->postJson('/api/v1/system-admin/space-creation-requests/'.$rejectTarget->public_id.'/reject', [
            'note' => 'policy mismatch',
        ])
            ->assertOk()
            ->assertJsonPath('data.request.request.status', 'rejected')
            ->assertJsonPath('data.request.request.rejectionVisibleUntil', fn ($value) => is_string($value) && $value !== '');

        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'space_creation_request_approved',
            'entity_type' => 'space_creation_request',
            'entity_public_id' => $pendingRequest->public_id,
        ]);

        $this->assertDatabaseHas('system_admin_audit_logs', [
            'action' => 'space_creation_request_rejected',
            'entity_type' => 'space_creation_request',
            'entity_public_id' => $rejectTarget->public_id,
        ]);
    }

    public function test_system_admin_can_resolve_reports_and_read_audit_logs(): void
    {
        $this->seed();

        $adminUser = $this->systemAdminUser();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $ownerMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->whereHas('user', fn ($query) => $query->where('email', 'owner@noccaro.local'))
            ->firstOrFail();
        $whisper = MapWhisper::query()->firstOrFail();

        $report = ContentReport::query()->create([
            'space_id' => $space->id,
            'reporter_membership_id' => $ownerMembership->id,
            'target_type' => 'whisper',
            'target_id' => $whisper->id,
            'reason_type' => 'privacy_risk',
            'detail' => '運営確認用の通報',
            'status' => 'open',
        ]);

        Sanctum::actingAs($adminUser);

        $this->getJson('/api/v1/system-admin/reports?status=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.report.id', $report->public_id)
            ->assertJsonPath('data.0.target.body', $whisper->body);

        $this->postJson('/api/v1/system-admin/reports/'.$report->public_id.'/resolve', [
            'resolutionType' => 'content_removed',
            'note' => 'ops removed content',
        ])
            ->assertOk()
            ->assertJsonPath('data.report.status', 'resolved')
            ->assertJsonPath('data.report.resolutionType', 'content_removed');

        $this->assertDatabaseHas('map_whispers', [
            'id' => $whisper->id,
            'status' => 'removed_by_owner',
        ]);

        $this->getJson('/api/v1/system-admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'report_resolved');
    }

    public function test_system_admin_can_manage_operation_posts_with_targeted_audience(): void
    {
        $this->seed();

        $adminUser = $this->systemAdminUser();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();

        Sanctum::actingAs($adminUser);

        $this->getJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts?category=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.post.category', 'owner');

        $operationCreated = $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'operation',
            'audienceType' => 'all_members',
            'title' => 'サービス運営からのお知らせ',
            'body' => 'operation category の確認です。',
            'status' => 'published',
            'notifyMembers' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.item.post.category', 'operation')
            ->assertJsonPath('data.item.post.notifyMembers', true)
            ->assertJsonPath('data.item.post.audienceType', 'all_members')
            ->assertJsonPath('data.item.post.recipientUserIds', []);

        $operationPostId = $operationCreated->json('data.item.post.id');

        $targetedOperationCreated = $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'operation',
            'audienceType' => 'targeted_users',
            'title' => 'あなた宛のお知らせ',
            'body' => 'targeted operation の確認です。',
            'status' => 'draft',
            'notifyMembers' => true,
            'recipientUserIds' => [$guest->public_id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.item.post.category', 'operation')
            ->assertJsonPath('data.item.post.audienceType', 'targeted_users')
            ->assertJsonPath('data.item.post.notifyMembers', true)
            ->assertJsonPath('data.item.post.recipientUserIds.0', $guest->public_id);

        $targetedOperationPostId = $targetedOperationCreated->json('data.item.post.id');

        $this->getJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts?category=operation')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.post.category', 'operation');

        $this->patchJson('/api/v1/system-admin/posts/'.$targetedOperationPostId, [
            'title' => 'あなた宛のお知らせ 改訂版',
            'recipientUserIds' => [$guest->public_id],
        ])
            ->assertOk()
            ->assertJsonPath('data.item.post.title', 'あなた宛のお知らせ 改訂版')
            ->assertJsonPath('data.item.post.recipientUserIds.0', $guest->public_id);

        $this->postJson('/api/v1/system-admin/posts/'.$targetedOperationPostId.'/publish', [
            'notifyMembers' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.item.post.status', 'published')
            ->assertJsonPath('data.item.post.notifyMembers', true);

        $this->postJson('/api/v1/system-admin/posts/'.$operationPostId.'/archive')
            ->assertOk()
            ->assertJsonPath('data.item.post.status', 'archived');

        $this->deleteJson('/api/v1/system-admin/posts/'.$operationPostId)
            ->assertNoContent();

        $this->assertDatabaseHas('space_posts', [
            'public_id' => $targetedOperationPostId,
            'category' => 'operation',
            'audience_type' => 'targeted_users',
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('space_posts', [
            'public_id' => $operationPostId,
            'category' => 'operation',
            'status' => 'deleted',
        ]);

        $this->assertDatabaseHas('space_post_deliveries', [
            'recipient_user_id' => $guest->id,
            'post_id' => SpacePost::query()->where('public_id', $targetedOperationPostId)->firstOrFail()->id,
        ]);

    }

    private function systemAdminUser(): User
    {
        $admin = SystemAdmin::query()->with('user')->firstOrFail();

        return $admin->user;
    }
}
