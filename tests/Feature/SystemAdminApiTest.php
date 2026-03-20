<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
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

    public function test_system_admin_can_manage_operation_and_personal_posts(): void
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
            'title' => 'サービス運営からのお知らせ',
            'body' => 'operation category の確認です。',
            'status' => 'published',
            'notifyMembers' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.item.post.category', 'operation')
            ->assertJsonPath('data.item.post.notifyMembers', true)
            ->assertJsonPath('data.item.recipient', null);

        $operationPostId = $operationCreated->json('data.item.post.id');

        $personalCreated = $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'personal',
            'title' => 'あなた宛のお知らせ',
            'body' => 'personal category の確認です。',
            'status' => 'draft',
            'notifyMembers' => false,
            'recipientUserId' => $guest->public_id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.item.post.category', 'personal')
            ->assertJsonPath('data.item.recipient.id', $guest->public_id);

        $personalPostId = $personalCreated->json('data.item.post.id');

        $this->getJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts?category=personal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.post.id', $personalPostId)
            ->assertJsonPath('data.0.recipient.id', $guest->public_id);

        $this->patchJson('/api/v1/system-admin/posts/'.$personalPostId, [
            'title' => 'あなた宛のお知らせ 改訂版',
        ])
            ->assertOk()
            ->assertJsonPath('data.item.post.title', 'あなた宛のお知らせ 改訂版');

        $this->postJson('/api/v1/system-admin/posts/'.$personalPostId.'/publish', [
            'notifyMembers' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.item.post.status', 'published');

        $this->postJson('/api/v1/system-admin/posts/'.$operationPostId.'/archive')
            ->assertOk()
            ->assertJsonPath('data.item.post.status', 'archived');

        $this->deleteJson('/api/v1/system-admin/posts/'.$operationPostId)
            ->assertNoContent();

        $this->assertDatabaseHas('space_posts', [
            'public_id' => $personalPostId,
            'category' => 'personal',
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('space_posts', [
            'public_id' => $operationPostId,
            'category' => 'operation',
            'status' => 'deleted',
        ]);

        $this->assertDatabaseHas('space_post_deliveries', [
            'recipient_user_id' => $guest->id,
            'post_id' => SpacePost::query()->where('public_id', $personalPostId)->firstOrFail()->id,
        ]);
    }

    private function systemAdminUser(): User
    {
        $admin = SystemAdmin::query()->with('user')->firstOrFail();

        return $admin->user;
    }
}
