<?php

namespace App\Support\SpaceCreationRequests;

final class SpaceCreationRequestConfig
{
    public const REJECTION_VISIBILITY_HOURS = 72;

    /**
     * @return array<string, mixed>
     */
    public static function defaultSpacePayload(): array
    {
        return [
            'description' => '',
            'maxOwnerCount' => 3,
            'whisperTtlMinutes' => 180,
            'whisperMaxLength' => 20,
            'locationGridMeters' => 120,
            'autoHideReportThreshold' => 5,
            'postLimitPerMinute' => 1,
            'postLimitPerTenMinutes' => 3,
            'locationJitterEnabled' => true,
            'status' => 'active',
        ];
    }
}
