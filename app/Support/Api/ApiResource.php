<?php

namespace App\Support\Api;

use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpaceNotification;
use App\Models\SpacePost;
use App\Models\User;
use App\Models\UserPushDevice;

class ApiResource
{
    public static function user(User $user): array
    {
        return [
            'id' => $user->public_id,
            'email' => $user->email,
            'displayName' => $user->display_name,
            'status' => $user->status,
            'emailVerifiedAt' => self::iso($user->email_verified_at),
            'lastLoginAt' => self::iso($user->last_login_at),
            'createdAt' => self::iso($user->created_at),
        ];
    }

    public static function space(Space $space): array
    {
        return [
            'id' => $space->public_id,
            'code' => $space->space_code,
            'name' => $space->name,
            'description' => $space->description,
            'joinPolicy' => $space->join_policy,
            'status' => $space->status,
            'maxOwnerCount' => $space->max_owner_count,
            'whisperTtlMinutes' => $space->whisper_ttl_minutes,
            'whisperMaxLength' => $space->whisper_max_length,
            'locationGridMeters' => $space->location_grid_meters,
            'locationJitterEnabled' => $space->location_jitter_enabled,
            'whisperAutoHideReportThreshold' => $space->whisper_auto_hide_report_threshold,
            'whisperRateLimitPerMinute' => $space->whisper_rate_limit_per_minute,
            'whisperRateLimitPer10Min' => $space->whisper_rate_limit_per_10min,
            'createdAt' => self::iso($space->created_at),
        ];
    }

    public static function membership(SpaceMembership $membership): array
    {
        return [
            'id' => $membership->public_id,
            'spaceId' => $membership->space?->public_id,
            'userId' => $membership->user?->public_id,
            'role' => $membership->role,
            'status' => self::publicMembershipStatus($membership->status),
            'joinedAt' => self::iso($membership->joined_at),
            'approvedAt' => self::iso($membership->approved_at),
            'leftAt' => self::iso($membership->left_at),
            'kickedAt' => self::iso($membership->kicked_at),
            'bannedAt' => self::iso($membership->banned_at),
            'suspendedUntil' => self::iso($membership->suspended_until),
            'muteUntil' => self::iso($membership->mute_until),
            'lastSeenAt' => self::iso($membership->last_seen_at),
            'createdAt' => self::iso($membership->created_at),
        ];
    }

    public static function joinedSpace(SpaceMembership $membership): array
    {
        return [
            'space' => self::space($membership->space),
            'membership' => self::membership($membership),
        ];
    }

    public static function post(SpacePost $post, bool $reactedByMe = false, ?int $reactionCount = null): array
    {
        return [
            'id' => $post->public_id,
            'spaceId' => $post->space?->public_id,
            'authorMembershipId' => $post->authorMembership?->public_id,
            'title' => $post->title,
            'body' => $post->body,
            'status' => $post->status,
            'notifyMembers' => $post->notify_members,
            'publishedAt' => self::iso($post->published_at),
            'visibleFrom' => self::iso($post->visible_from),
            'visibleTo' => self::iso($post->visible_to),
            'reactionCount' => $reactionCount ?? ($post->reactions_count ?? 0),
            'reactedByMe' => $reactedByMe,
            'createdAt' => self::iso($post->created_at),
            'updatedAt' => self::iso($post->updated_at),
        ];
    }

    public static function whisper(MapWhisper $whisper): array
    {
        return [
            'id' => $whisper->public_id,
            'spaceId' => $whisper->space?->public_id,
            'membershipId' => $whisper->membership?->public_id,
            'body' => $whisper->body,
            'status' => $whisper->status,
            'displayLat' => (float) $whisper->display_lat,
            'displayLng' => (float) $whisper->display_lng,
            'displayRadiusM' => $whisper->display_radius_m,
            'expiresAt' => self::iso($whisper->expires_at),
            'reportCount' => $whisper->report_count,
            'createdAt' => self::iso($whisper->created_at),
        ];
    }

    public static function report(ContentReport $report): array
    {
        $targetId = match ($report->target_type) {
            'whisper' => MapWhisper::query()->find($report->target_id)?->public_id ?? (string) $report->target_id,
            'post' => SpacePost::query()->find($report->target_id)?->public_id ?? (string) $report->target_id,
            'member' => SpaceMembership::query()->find($report->target_id)?->public_id ?? (string) $report->target_id,
            default => (string) $report->target_id,
        };

        return [
            'id' => $report->public_id,
            'spaceId' => $report->space?->public_id,
            'reporterMembershipId' => $report->reporterMembership?->public_id,
            'targetType' => $report->target_type,
            'targetId' => $targetId,
            'reasonType' => $report->reason_type,
            'detail' => $report->detail,
            'status' => $report->status,
            'handledByMembershipId' => $report->handledByMembership?->public_id,
            'handledAt' => self::iso($report->handled_at),
            'resolutionType' => $report->resolution_type,
            'createdAt' => self::iso($report->created_at),
        ];
    }

    public static function device(UserPushDevice $device): array
    {
        return [
            'id' => self::devicePublicId($device),
            'platform' => $device->platform,
            'pushToken' => $device->push_token,
            'deviceUuid' => $device->device_uuid,
            'appVersion' => $device->app_version,
            'osVersion' => $device->os_version,
            'isActive' => $device->is_active,
            'lastSeenAt' => self::iso($device->last_seen_at),
            'createdAt' => self::iso($device->created_at),
            'updatedAt' => self::iso($device->updated_at),
        ];
    }

    public static function adminJoinRequestItem(SpaceMembership $membership): array
    {
        return [
            'membership' => self::membership($membership),
            'user' => self::user($membership->user),
        ];
    }

    public static function adminMemberItem(SpaceMembership $membership): array
    {
        return [
            'membership' => self::membership($membership),
            'user' => self::user($membership->user),
        ];
    }

    public static function adminReportItem(ContentReport $report): array
    {
        $target = [
            'type' => $report->target_type,
        ];

        if ($report->target_type === 'whisper') {
            $whisper = MapWhisper::query()->with(['space', 'membership'])->find($report->target_id);
            if ($whisper) {
                $target['whisper'] = self::whisper($whisper);
            }
        }

        return [
            'report' => self::report($report),
            'target' => $target,
            'reporter' => [
                'membership' => self::membership($report->reporterMembership),
                'user' => self::user($report->reporterMembership->user),
            ],
        ];
    }

    public static function notification(SpaceNotification $notification): array
    {
        $sourceId = null;
        if ($notification->source_type === 'post' && $notification->source_id) {
            $sourceId = SpacePost::query()->find($notification->source_id)?->public_id;
        }

        return [
            'id' => $notification->public_id,
            'spaceId' => $notification->space?->public_id,
            'sourceType' => $notification->source_type,
            'sourceId' => $sourceId,
            'createdByMembershipId' => $notification->createdByMembership?->public_id,
            'title' => $notification->title,
            'body' => $notification->body,
            'targetScope' => $notification->target_scope,
            'status' => $notification->status,
            'scheduledAt' => self::iso($notification->scheduled_at),
            'sentAt' => self::iso($notification->sent_at),
            'createdAt' => self::iso($notification->created_at),
            'updatedAt' => self::iso($notification->updated_at),
        ];
    }

    public static function publicMembershipStatus(string $status): string
    {
        return $status === 'rejected' ? 'left' : $status;
    }

    private static function devicePublicId(UserPushDevice $device): string
    {
        return sprintf('%s:%s', $device->platform, substr(sha1($device->push_token), 0, 24));
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toIso8601String();
    }
}
