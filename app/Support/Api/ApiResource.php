<?php

namespace App\Support\Api;

use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceMembership;
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
            'title' => $post->title,
            'body' => $post->body,
            'publishedAt' => self::iso($post->published_at),
            'reactionCount' => $reactionCount ?? ($post->reactions_count ?? 0),
            'reactedByMe' => $reactedByMe,
        ];
    }

    public static function whisper(MapWhisper $whisper): array
    {
        return [
            'id' => $whisper->public_id,
            'spaceId' => $whisper->space?->public_id,
            'body' => $whisper->body,
            'displayLat' => (float) $whisper->display_lat,
            'displayLng' => (float) $whisper->display_lng,
            'displayRadiusM' => $whisper->display_radius_m,
            'expiresAt' => self::iso($whisper->expires_at),
            'status' => $whisper->status,
            'reportCount' => $whisper->report_count,
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
            'targetType' => $report->target_type,
            'targetId' => $targetId,
            'reasonType' => $report->reason_type,
            'detail' => $report->detail,
            'status' => $report->status,
            'handledAt' => self::iso($report->handled_at),
            'resolutionType' => $report->resolution_type,
            'createdAt' => self::iso($report->created_at),
        ];
    }

    public static function device(UserPushDevice $device): array
    {
        return [
            'platform' => $device->platform,
            'pushToken' => $device->push_token,
            'deviceUuid' => $device->device_uuid,
            'appVersion' => $device->app_version,
            'osVersion' => $device->os_version,
            'isActive' => $device->is_active,
            'lastSeenAt' => self::iso($device->last_seen_at),
            'createdAt' => self::iso($device->created_at),
        ];
    }

    public static function publicMembershipStatus(string $status): string
    {
        return $status === 'rejected' ? 'left' : $status;
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toIso8601String();
    }
}
