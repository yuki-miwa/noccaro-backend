<?php

namespace Tests\Feature;

use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_and_update_space_settings(): void
    {
        [$owner, $space] = $this->seedOwnerContext();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id)
            ->assertOk()
            ->assertJsonPath('data.space.code', 'NOC2026')
            ->assertJsonPath('data.membership.role', 'primary_owner');

        $this->patchJson('/api/v1/admin/spaces/'.$space->public_id, [
            'name' => 'Noccaro 運営コミュニティ',
            'description' => '管理者向けに更新した説明です。',
            'joinPolicy' => 'auto_approve',
            'whisperTtlMinutes' => 180,
            'whisperMaxLength' => 30,
            'locationGridMeters' => 150,
            'locationJitterEnabled' => true,
            'whisperAutoHideReportThreshold' => 5,
            'whisperRateLimitPerMinute' => 2,
            'whisperRateLimitPer10Min' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('data.space.name', 'Noccaro 運営コミュニティ')
            ->assertJsonPath('data.space.joinPolicy', 'auto_approve')
            ->assertJsonPath('data.space.autoHideReportThreshold', 5)
            ->assertJsonPath('data.space.postLimitPerMinute', 2)
            ->assertJsonPath('data.space.postLimitPerTenMinutes', 6)
            ->assertJsonPath('data.space.whisperRateLimitPerMinute', 2)
            ->assertJsonPath('data.space.whisperRateLimitPer10Min', 6);

        $this->getJson('/api/v1/spaces/'.$space->public_id)
            ->assertOk()
            ->assertJsonPath('data.space.name', 'Noccaro 運営コミュニティ')
            ->assertJsonPath('data.space.description', '管理者向けに更新した説明です。')
            ->assertJsonPath('data.space.autoHideReportThreshold', 5)
            ->assertJsonPath('data.space.postLimitPerMinute', 2)
            ->assertJsonPath('data.space.postLimitPerTenMinutes', 6)
            ->assertJsonPath('data.space.whisperRateLimitPerMinute', 2)
            ->assertJsonPath('data.space.whisperRateLimitPer10Min', 6);

        $this->assertDatabaseHas('spaces', [
            'id' => $space->id,
            'name' => 'Noccaro 運営コミュニティ',
            'join_policy' => 'auto_approve',
        ]);
    }

    public function test_owner_can_approve_and_reject_join_requests(): void
    {
        [$owner, $space] = $this->seedOwnerContext();
        $extraPending = $this->createPendingMembership($space);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id.'/join-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $pendingMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->whereHas('user', fn ($query) => $query->where('email', 'pending@noccaro.local'))
            ->firstOrFail();

        $this->postJson('/api/v1/admin/memberships/'.$pendingMembership->public_id.'/approve', [
            'note' => '歓迎します',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonPath('data.membership.approvedAt', fn (?string $value) => $value !== null);

        $this->postJson('/api/v1/admin/memberships/'.$extraPending->public_id.'/reject', [
            'note' => '今回は見送り',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'left');

        $this->assertDatabaseHas('member_actions', [
            'target_membership_id' => $pendingMembership->id,
            'action_type' => 'approve',
        ]);

        $this->assertDatabaseHas('member_actions', [
            'target_membership_id' => $extraPending->id,
            'action_type' => 'reject',
        ]);
    }

    public function test_owner_can_list_members_and_moderate_them(): void
    {
        [$owner, $space, $ownerMembership] = $this->seedOwnerContext();
        $guestMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->whereHas('user', fn ($query) => $query->where('email', 'guest@noccaro.local'))
            ->firstOrFail();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id.'/members?status=all&role=all&limit=50')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.hasMore', false);

        $this->patchJson('/api/v1/admin/memberships/'.$guestMembership->public_id, [
            'role' => 'owner',
            'reason' => '運営補助を依頼',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.role', 'owner');

        $muteUntil = now()->addHours(6)->toIso8601String();
        $this->patchJson('/api/v1/admin/memberships/'.$guestMembership->public_id, [
            'muteUntil' => $muteUntil,
            'reason' => '一時ミュート',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.muteUntil', $muteUntil);

        $suspendedUntil = now()->addDay()->toIso8601String();
        $this->patchJson('/api/v1/admin/memberships/'.$guestMembership->public_id, [
            'status' => 'suspended',
            'suspendedUntil' => $suspendedUntil,
            'reason' => '確認のため停止',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'suspended')
            ->assertJsonPath('data.membership.suspendedUntil', $suspendedUntil);

        $this->patchJson('/api/v1/admin/memberships/'.$ownerMembership->public_id, [
            'status' => 'banned',
            'reason' => '主オーナーには適用不可',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_owner_can_manage_posts_and_notifications(): void
    {
        [$owner, $space] = $this->seedOwnerContext();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id.'/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $created = $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'owner',
            'audienceType' => 'targeted_users',
            'recipientUserIds' => [$guest->public_id],
            'title' => '運営メモ',
            'body' => 'これは draft 記事です。',
            'status' => 'draft',
            'notifyMembers' => false,
            'visibleFrom' => null,
            'visibleTo' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.post.category', 'owner')
            ->assertJsonPath('data.post.audienceType', 'targeted_users')
            ->assertJsonPath('data.post.recipientUserIds.0', $guest->public_id)
            ->assertJsonPath('data.post.status', 'draft');

        $postId = $created->json('data.post.id');

        $this->patchJson('/api/v1/admin/posts/'.$postId, [
            'title' => '運営メモ 更新版',
            'body' => '公開前に本文を更新しました。',
            'status' => 'draft',
            'audienceType' => 'all_members',
        ])
            ->assertOk()
            ->assertJsonPath('data.post.title', '運営メモ 更新版')
            ->assertJsonPath('data.post.audienceType', 'all_members')
            ->assertJsonPath('data.post.recipientUserIds', []);

        $this->postJson('/api/v1/admin/posts/'.$postId.'/publish', [
            'notifyMembers' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.post.status', 'published')
            ->assertJsonPath('data.post.notifyMembers', true);

        $this->postJson('/api/v1/admin/posts/'.$postId.'/archive')
            ->assertOk()
            ->assertJsonPath('data.post.status', 'archived');

        $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/notifications', [
            'sourceType' => 'system',
            'title' => '手動お知らせ',
            'body' => '管理画面から送信する通知です。',
            'targetScope' => 'all_active_members',
            'scheduledAt' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.notification.title', '手動お知らせ');

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id.'/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->deleteJson('/api/v1/admin/posts/'.$postId)
            ->assertNoContent();

        $this->assertSoftDeleted('space_posts', [
            'public_id' => $postId,
        ]);

        $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'operation',
            'title' => '不正なカテゴリ',
            'body' => 'owner admin では作れない',
            'status' => 'draft',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'owner',
            'audienceType' => 'targeted_users',
            'recipientUserIds' => [$guest->public_id],
            'title' => '指定対象への通知あり投稿',
            'body' => 'targeted_users でも通知できる',
            'status' => 'draft',
            'notifyMembers' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.post.audienceType', 'targeted_users')
            ->assertJsonPath('data.post.notifyMembers', true);
    }

    public function test_owner_can_resolve_reports_and_remove_whispers(): void
    {
        [$owner, $space] = $this->seedOwnerContext();
        $guestMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->whereHas('user', fn ($query) => $query->where('email', 'guest@noccaro.local'))
            ->firstOrFail();

        $secondWhisper = MapWhisper::query()->create([
            'space_id' => $space->id,
            'membership_id' => $guestMembership->id,
            'body' => '奥の席が空いています',
            'status' => 'active',
            'grid_key' => '35.68010:139.76710',
            'display_lat' => 35.6801,
            'display_lng' => 139.7671,
            'display_radius_m' => 72,
            'expires_at' => now()->addHours(2),
            'report_count' => 0,
        ]);

        Sanctum::actingAs($owner);

        $whisperId = MapWhisper::query()->where('space_id', $space->id)->oldest()->firstOrFail()->public_id;

        $reportResponse = $this->postJson('/api/v1/whispers/'.$whisperId.'/report', [
            'reasonType' => 'privacy_risk',
            'detail' => '位置情報の粒度が気になるため確認',
        ])
            ->assertCreated()
            ->assertJsonPath('data.report.status', 'open');

        $reportId = $reportResponse->json('data.report.id');

        $this->getJson('/api/v1/admin/spaces/'.$space->public_id.'/reports?status=all&targetType=all&reasonType=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.report.id', $reportId);

        $this->postJson('/api/v1/admin/reports/'.$reportId.'/resolve', [
            'resolutionType' => 'content_removed',
            'note' => 'owner 対応で非表示化',
        ])
            ->assertOk()
            ->assertJsonPath('data.report.status', 'resolved')
            ->assertJsonPath('data.whisper.status', 'removed_by_owner');

        $this->postJson('/api/v1/admin/whispers/'.$secondWhisper->public_id.'/remove', [
            'reason' => '重複投稿のため削除',
        ])
            ->assertOk()
            ->assertJsonPath('data.whisper.status', 'removed_by_owner');
    }

    private function seedOwnerContext(): array
    {
        $this->seed();

        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $ownerMembership = SpaceMembership::query()
            ->where('space_id', $space->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        return [$owner, $space, $ownerMembership];
    }

    private function createPendingMembership(Space $space): SpaceMembership
    {
        $user = User::factory()->create([
            'email' => 'pending-extra@example.com',
            'display_name' => 'Extra Pending',
        ]);

        return SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'role' => 'guest',
            'status' => 'pending',
        ]);
    }
}
