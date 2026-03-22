<?php

namespace Tests\Feature;

use App\Contracts\Push\AndroidPushClient;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Models\UserPushDevice;
use App\Support\Push\PushSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoticePushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
    }

    public function test_published_owner_notice_sends_only_to_active_android_members_with_notifications_enabled(): void
    {
        $this->seed();

        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $disabledUser = User::factory()->create([
            'email' => 'disabled-notice@example.com',
            'display_name' => 'Disabled Notice',
            'status' => 'active',
            'notifications_enabled' => false,
        ]);
        $suspendedUser = User::factory()->create([
            'email' => 'suspended-notice@example.com',
            'display_name' => 'Suspended Notice',
            'status' => 'active',
            'notifications_enabled' => true,
        ]);

        SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $disabledUser->id,
            'role' => 'guest',
            'status' => 'active',
            'joined_at' => now(),
            'approved_at' => now(),
        ]);
        SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $suspendedUser->id,
            'role' => 'guest',
            'status' => 'suspended',
            'joined_at' => now(),
            'approved_at' => now(),
            'suspended_until' => now()->addDay(),
        ]);

        $guestDevice = $this->createAndroidDevice($guest, 'android-guest-token');
        $this->createAndroidDevice($disabledUser, 'android-disabled-token');
        $this->createAndroidDevice($suspendedUser, 'android-suspended-token');
        $this->createIosDevice($guest, 'ios-guest-token');

        $fakeClient = new FakeAndroidPushClient([
            'android-guest-token' => PushSendResult::sent('projects/demo/messages/1'),
        ]);
        $this->app->instance(AndroidPushClient::class, $fakeClient);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'owner',
            'title' => 'Push 対象のお知らせ',
            'body' => 'Android Push が送られます。',
            'status' => 'published',
            'notifyMembers' => true,
            'audienceType' => 'all_members',
        ])
            ->assertCreated()
            ->assertJsonPath('data.post.status', 'published')
            ->assertJsonPath('data.post.notifyMembers', true);

        $postId = $response->json('data.post.id');
        $post = SpacePost::query()->where('public_id', $postId)->firstOrFail();
        $notification = SpaceNotification::query()->where('source_id', $post->id)->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'user_push_device_id' => $guestDevice->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->id,
            'push_token' => 'android-disabled-token',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->id,
            'push_token' => 'android-suspended-token',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->id,
            'push_token' => 'ios-guest-token',
        ]);

        $this->assertCount(1, $fakeClient->messages);
        $this->assertSame('android-guest-token', $fakeClient->messages[0]['deviceToken']);
        $this->assertSame([
            'type' => 'notice',
            'postId' => $post->public_id,
            'spaceId' => $space->public_id,
            'category' => 'owner',
        ], $fakeClient->messages[0]['data']);
    }

    public function test_published_operation_notice_sends_only_to_targeted_recipients(): void
    {
        $this->seed();

        $adminUser = SystemAdmin::query()->with('user')->firstOrFail()->user;
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();

        $guestDevice = $this->createAndroidDevice($guest, 'android-targeted-guest');
        $this->createAndroidDevice($owner, 'android-owner-token');

        $fakeClient = new FakeAndroidPushClient([
            'android-targeted-guest' => PushSendResult::sent('projects/demo/messages/targeted'),
        ]);
        $this->app->instance(AndroidPushClient::class, $fakeClient);

        Sanctum::actingAs($adminUser);

        $response = $this->postJson('/api/v1/system-admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'operation',
            'title' => '対象者だけへ',
            'body' => 'targeted_users の通知です。',
            'status' => 'published',
            'notifyMembers' => true,
            'audienceType' => 'targeted_users',
            'recipientUserIds' => [$guest->public_id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.item.post.status', 'published')
            ->assertJsonPath('data.item.post.notifyMembers', true);

        $post = SpacePost::query()->where('public_id', $response->json('data.item.post.id'))->firstOrFail();
        $notification = SpaceNotification::query()->where('source_id', $post->id)->firstOrFail();

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'user_push_device_id' => $guestDevice->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->id,
            'push_token' => 'android-owner-token',
        ]);
        $this->assertCount(1, $fakeClient->messages);
        $this->assertSame('operation', $fakeClient->messages[0]['data']['category']);
    }

    public function test_invalid_android_token_is_marked_inactive_and_notification_status_is_tracked(): void
    {
        $this->seed();

        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();
        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $extraUser = User::factory()->create([
            'email' => 'push-extra@example.com',
            'display_name' => 'Push Extra',
            'status' => 'active',
            'notifications_enabled' => true,
        ]);

        SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $extraUser->id,
            'role' => 'guest',
            'status' => 'active',
            'joined_at' => now(),
            'approved_at' => now(),
        ]);

        $guestDevice = $this->createAndroidDevice($guest, 'android-valid-token');
        $invalidDevice = $this->createAndroidDevice($extraUser, 'android-invalid-token');

        $fakeClient = new FakeAndroidPushClient([
            'android-valid-token' => PushSendResult::sent('projects/demo/messages/ok'),
            'android-invalid-token' => PushSendResult::invalidToken('UNREGISTERED', 'Requested entity was not found.'),
        ]);
        $this->app->instance(AndroidPushClient::class, $fakeClient);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/spaces/'.$space->public_id.'/posts', [
            'category' => 'owner',
            'title' => '一部失敗する通知',
            'body' => 'invalid token の検証です。',
            'status' => 'published',
            'notifyMembers' => true,
            'audienceType' => 'all_members',
        ])->assertCreated();

        $post = SpacePost::query()->where('public_id', $response->json('data.post.id'))->firstOrFail();
        $notification = SpaceNotification::query()->where('source_id', $post->id)->firstOrFail();

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'user_push_device_id' => $guestDevice->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'user_push_device_id' => $invalidDevice->id,
            'status' => 'invalid_token',
        ]);
        $this->assertDatabaseHas('user_push_devices', [
            'id' => $invalidDevice->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'partial_failure',
        ]);
    }

    private function createAndroidDevice(User $user, string $pushToken): UserPushDevice
    {
        return UserPushDevice::query()->create([
            'user_id' => $user->id,
            'platform' => 'android',
            'push_token' => $pushToken,
            'device_uuid' => 'device-'.md5($pushToken),
            'app_version' => '1.0.0',
            'os_version' => 'Android 15',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    private function createIosDevice(User $user, string $pushToken): UserPushDevice
    {
        return UserPushDevice::query()->create([
            'user_id' => $user->id,
            'platform' => 'ios',
            'push_token' => $pushToken,
            'device_uuid' => 'device-'.md5($pushToken),
            'app_version' => '1.0.0',
            'os_version' => 'iOS 18',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }
}

class FakeAndroidPushClient implements AndroidPushClient
{
    public array $messages = [];

    public function __construct(private readonly array $resultsByToken = []) {}

    public function configured(): bool
    {
        return true;
    }

    public function send(string $deviceToken, array $notification, array $data): PushSendResult
    {
        $this->messages[] = [
            'deviceToken' => $deviceToken,
            'notification' => $notification,
            'data' => $data,
        ];

        return $this->resultsByToken[$deviceToken] ?? PushSendResult::sent('projects/demo/messages/default');
    }
}
