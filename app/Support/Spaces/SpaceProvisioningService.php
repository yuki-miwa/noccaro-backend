<?php

namespace App\Support\Spaces;

use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\User;

class SpaceProvisioningService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{space: Space, primaryOwnerMembership: SpaceMembership}
     */
    public function createSpaceWithPrimaryOwner(array $payload, User $creator, User $primaryOwner): array
    {
        $space = Space::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => $this->normalizeDescription($payload['description'] ?? null),
            'space_code' => trim((string) $payload['spaceCode']),
            'join_policy' => $payload['joinPolicy'],
            'status' => $payload['status'] ?? 'active',
            'max_owner_count' => (int) $payload['maxOwnerCount'],
            'whisper_ttl_minutes' => (int) $payload['whisperTtlMinutes'],
            'whisper_auto_hide_report_threshold' => (int) $payload['autoHideReportThreshold'],
            'whisper_rate_limit_per_minute' => (int) $payload['postLimitPerMinute'],
            'whisper_rate_limit_per_10min' => (int) $payload['postLimitPerTenMinutes'],
            'whisper_max_length' => (int) $payload['whisperMaxLength'],
            'location_grid_meters' => (int) $payload['locationGridMeters'],
            'location_jitter_enabled' => (bool) $payload['locationJitterEnabled'],
            'created_by_user_id' => $creator->id,
        ]);

        $membership = SpaceMembership::query()->updateOrCreate(
            [
                'space_id' => $space->id,
                'user_id' => $primaryOwner->id,
            ],
            [
                'role' => 'primary_owner',
                'status' => 'active',
                'joined_at' => now(),
                'approved_at' => now(),
                'approved_by_membership_id' => null,
                'left_at' => null,
                'kicked_at' => null,
                'suspended_until' => null,
                'banned_at' => null,
                'mute_until' => null,
                'last_seen_at' => now(),
            ],
        );

        return [
            'space' => $space,
            'primaryOwnerMembership' => $membership,
        ];
    }

    private function normalizeDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return trim((string) $description);
    }
}
