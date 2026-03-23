<?php

namespace App\Support\Whispers;

use App\Models\MapWhisper;
use App\Models\Space;

class WhisperLifecycleService
{
    public function __construct(private readonly WhisperImageService $images) {}

    public function expireOverdueWhispers(?Space $space = null, int $chunkSize = 100): int
    {
        $query = MapWhisper::query()
            ->with('image')
            ->where('status', 'active')
            ->where('expires_at', '<=', now());

        if ($space) {
            $query->where('space_id', $space->id);
        }

        $expiredCount = 0;

        $query->chunkById($chunkSize, function ($whispers) use (&$expiredCount): void {
            foreach ($whispers as $whisper) {
                $whisper->forceFill([
                    'status' => 'expired',
                ])->save();
                $this->images->purgeForWhisper($whisper);
                $expiredCount++;
            }
        });

        return $expiredCount;
    }

    public function hideByReport(MapWhisper $whisper): void
    {
        $whisper->forceFill([
            'status' => 'hidden_by_report',
            'hidden_at' => now(),
        ])->save();

        $this->images->purgeForWhisper($whisper);
    }

    public function remove(MapWhisper $whisper, ?int $removedByMembershipId, string $status = 'removed_by_owner'): void
    {
        $whisper->forceFill([
            'status' => $status,
            'removed_at' => now(),
            'removed_by_membership_id' => $removedByMembershipId,
        ])->save();

        $this->images->purgeForWhisper($whisper);
    }
}
