<?php

namespace App\Support\Whispers;

class WhisperLocationService
{
    public function toDisplayLocation(float $exactLat, float $exactLng, int $gridMeters, bool $jitterEnabled): array
    {
        $latStep = $gridMeters / 111320;
        $lngStep = $gridMeters / max(cos(deg2rad($exactLat)) * 111320, 1);

        $baseLat = round($exactLat / $latStep) * $latStep;
        $baseLng = round($exactLng / $lngStep) * $lngStep;

        $jitterSeed = crc32(number_format($exactLat, 5, '.', '').':'.number_format($exactLng, 5, '.', ''));
        $latJitter = 0.0;
        $lngJitter = 0.0;

        if ($jitterEnabled) {
            $latJitter = ((($jitterSeed % 21) - 10) / 10) * ($latStep * 0.12);
            $lngJitter = ((((int) floor($jitterSeed / 21)) % 21) - 10) / 10 * ($lngStep * 0.12);
        }

        $displayLat = round($baseLat + $latJitter, 7);
        $displayLng = round($baseLng + $lngJitter, 7);

        return [
            'gridKey' => sprintf('%0.5f:%0.5f', $baseLat, $baseLng),
            'displayLat' => $displayLat,
            'displayLng' => $displayLng,
            'displayRadiusM' => max((int) round($gridMeters * 0.6), 30),
        ];
    }
}
