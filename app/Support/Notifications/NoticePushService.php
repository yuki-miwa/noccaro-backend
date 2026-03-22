<?php

namespace App\Support\Notifications;

use App\Contracts\Push\AndroidPushClient;
use App\Jobs\SendSpaceNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\UserPushDevice;
use Illuminate\Support\Facades\Log;

class NoticePushService
{
    private const FINAL_STATUSES = [
        'sent',
        'partial_failure',
        'failed',
        'no_recipients',
        'delivery_disabled',
    ];

    public function __construct(private readonly AndroidPushClient $pushClient) {}

    public function queuePostNotification(SpacePost $post, SpaceMembership $actor): SpaceNotification
    {
        $notification = SpaceNotification::query()->firstOrNew([
            'space_id' => $post->space_id,
            'source_type' => 'post',
            'source_id' => $post->id,
        ]);

        if ($notification->exists && in_array($notification->status, self::FINAL_STATUSES, true)) {
            return $notification;
        }

        $notification->fill([
            'created_by_membership_id' => $actor->id,
            'title' => $post->title,
            'body' => mb_substr($post->body, 0, 200),
            'target_scope' => $post->audience_type === 'targeted_users' ? 'targeted_users' : 'all_active_members',
            'status' => 'queued',
            'scheduled_at' => now(),
            'sent_at' => null,
        ]);
        $notification->save();

        SendSpaceNotificationJob::dispatch($notification->id);

        return $notification;
    }

    public function processNotification(int $notificationId): void
    {
        $notification = SpaceNotification::query()->with('deliveries')->find($notificationId);

        if (! $notification || in_array($notification->status, self::FINAL_STATUSES, true)) {
            return;
        }

        $post = SpacePost::query()->with(['space', 'deliveries'])->find($notification->source_id);

        if (! $post || $notification->source_type !== 'post' || ! $post->notify_members || $post->status !== 'published') {
            $notification->forceFill([
                'status' => 'failed',
                'sent_at' => null,
            ])->save();
            Log::warning('notice_push.notification_invalid', [
                'notificationId' => $notification->public_id,
                'postId' => $post?->public_id,
            ]);

            return;
        }

        if (! $this->pushClient->configured()) {
            $notification->forceFill([
                'status' => 'delivery_disabled',
                'sent_at' => null,
            ])->save();
            Log::warning('notice_push.fcm_not_configured', [
                'notificationId' => $notification->public_id,
                'postId' => $post->public_id,
            ]);

            return;
        }

        $notification->forceFill(['status' => 'processing'])->save();
        $devices = $this->eligibleAndroidDevices($post);

        if ($devices->isEmpty()) {
            $notification->forceFill([
                'status' => 'no_recipients',
                'sent_at' => null,
            ])->save();
            Log::info('notice_push.no_recipients', [
                'notificationId' => $notification->public_id,
                'postId' => $post->public_id,
                'audienceType' => $post->audience_type,
            ]);

            return;
        }

        $payload = $this->payloadForPost($post);

        foreach ($devices as $device) {
            $delivery = NotificationDelivery::query()->firstOrNew([
                'notification_id' => $notification->id,
                'push_token' => $device->push_token,
            ]);

            if ($delivery->exists && in_array($delivery->status, ['sent', 'invalid_token'], true)) {
                continue;
            }

            $delivery->fill([
                'post_id' => $post->id,
                'user_id' => $device->user_id,
                'user_push_device_id' => $device->id,
                'platform' => $device->platform,
                'transport' => 'fcm_http_v1',
                'status' => 'queued',
                'response_code' => null,
                'response_body' => null,
                'fcm_message_id' => null,
                'last_error' => null,
                'sent_at' => null,
                'failed_at' => null,
            ]);
            $delivery->save();

            $result = $this->pushClient->send($device->push_token, $payload['notification'], $payload['data']);

            if ($result->isSent()) {
                $delivery->forceFill([
                    'status' => 'sent',
                    'fcm_message_id' => $result->messageId,
                    'response_body' => $result->rawResponse,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ])->save();

                continue;
            }

            if ($result->isInvalidToken()) {
                $device->forceFill(['is_active' => false])->save();
                $delivery->forceFill([
                    'status' => 'invalid_token',
                    'response_code' => $result->code,
                    'response_body' => $result->rawResponse,
                    'failed_at' => now(),
                    'last_error' => $result->message,
                ])->save();
                Log::warning('notice_push.invalid_token', [
                    'notificationId' => $notification->public_id,
                    'postId' => $post->public_id,
                    'userId' => $device->user?->public_id,
                    'deviceId' => $device->id,
                ]);

                continue;
            }

            $delivery->forceFill([
                'status' => 'failed',
                'response_code' => $result->code,
                'response_body' => $result->rawResponse,
                'failed_at' => now(),
                'last_error' => $result->message,
            ])->save();
            Log::error('notice_push.delivery_failed', [
                'notificationId' => $notification->public_id,
                'postId' => $post->public_id,
                'userId' => $device->user?->public_id,
                'deviceId' => $device->id,
                'code' => $result->code,
                'message' => $result->message,
            ]);
        }

        $this->finalizeNotificationStatus($notification);
    }

    private function eligibleAndroidDevices(SpacePost $post)
    {
        $query = UserPushDevice::query()
            ->select('user_push_devices.*')
            ->with('user')
            ->join('users', 'users.id', '=', 'user_push_devices.user_id')
            ->join('space_memberships', function ($join) use ($post): void {
                $join->on('space_memberships.user_id', '=', 'users.id')
                    ->where('space_memberships.space_id', '=', $post->space_id)
                    ->where('space_memberships.status', '=', 'active');
            })
            ->where('user_push_devices.platform', 'android')
            ->where('user_push_devices.is_active', true)
            ->where('users.notifications_enabled', true);

        if ($post->audience_type === 'targeted_users') {
            $query->join('space_post_deliveries', function ($join) use ($post): void {
                $join->on('space_post_deliveries.recipient_user_id', '=', 'users.id')
                    ->where('space_post_deliveries.post_id', '=', $post->id);
            });
        }

        return $query->distinct()->get();
    }

    private function finalizeNotificationStatus(SpaceNotification $notification): void
    {
        $notification->load('deliveries');
        $deliveries = $notification->deliveries;
        $sentCount = $deliveries->where('status', 'sent')->count();
        $failureCount = $deliveries->whereIn('status', ['failed', 'invalid_token'])->count();

        if ($deliveries->isEmpty()) {
            $notification->forceFill([
                'status' => 'no_recipients',
                'sent_at' => null,
            ])->save();

            return;
        }

        if ($sentCount > 0 && $failureCount === 0) {
            $notification->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();

            return;
        }

        if ($sentCount > 0) {
            $notification->forceFill([
                'status' => 'partial_failure',
                'sent_at' => now(),
            ])->save();

            return;
        }

        $notification->forceFill([
            'status' => 'failed',
            'sent_at' => null,
        ])->save();
    }

    private function payloadForPost(SpacePost $post): array
    {
        return [
            'notification' => [
                'title' => $post->category === 'operation' ? '運営からのお知らせ' : 'オーナーからのお知らせ',
                'body' => mb_strimwidth($post->title, 0, 120, '…', 'UTF-8'),
            ],
            'data' => [
                'type' => 'notice',
                'postId' => $post->public_id,
                'spaceId' => $post->space->public_id,
                'category' => $post->category,
            ],
        ];
    }
}
