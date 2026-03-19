<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Support\Api\ApiResource;
use App\Support\Spaces\MembershipGuard;
use App\Support\Whispers\WhisperLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhisperController extends ApiController
{
    public function index(Request $request, Space $space, MembershipGuard $guard): JsonResponse
    {
        $guard->requireActiveMembership($request->user(), $space);

        MapWhisper::query()
            ->where('space_id', $space->id)
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $limit = (int) ($request->integer('limit') ?: 100);
        $whispersQuery = MapWhisper::query()
            ->with('space')
            ->where('space_id', $space->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->limit($limit);

        foreach (['south', 'west', 'north', 'east'] as $field) {
            if ($request->filled($field) && ! is_numeric($request->input($field))) {
                throw new ApiException('VALIDATION_ERROR', '座標パラメータが不正です。', 422, ['field' => $field]);
            }
        }

        if ($request->filled('south')) {
            $whispersQuery->where('display_lat', '>=', (float) $request->input('south'));
        }
        if ($request->filled('north')) {
            $whispersQuery->where('display_lat', '<=', (float) $request->input('north'));
        }
        if ($request->filled('west')) {
            $whispersQuery->where('display_lng', '>=', (float) $request->input('west'));
        }
        if ($request->filled('east')) {
            $whispersQuery->where('display_lng', '<=', (float) $request->input('east'));
        }

        $whispers = $whispersQuery->get();

        return $this->collection(
            $whispers->map(fn (MapWhisper $whisper) => ApiResource::whisper($whisper))->all(),
            [
                'expiresAtMin' => optional($whispers->min('expires_at'))?->toIso8601String(),
                'expiresAtMax' => optional($whispers->max('expires_at'))?->toIso8601String(),
            ],
        );
    }

    public function store(
        Request $request,
        Space $space,
        MembershipGuard $guard,
        WhisperLocationService $locationService,
    ): JsonResponse {
        $payload = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:30', 'not_regex:/[\r\n]/'],
            'exactLat' => ['required', 'numeric'],
            'exactLng' => ['required', 'numeric'],
        ]);

        $membership = $guard->requireActiveMembership($request->user(), $space);
        $this->assertNotMuted($membership);
        $this->assertWithinRateLimit($membership, $space);

        $display = $locationService->toDisplayLocation(
            (float) $payload['exactLat'],
            (float) $payload['exactLng'],
            $space->location_grid_meters,
            $space->location_jitter_enabled,
        );

        $whisper = MapWhisper::query()->create([
            'space_id' => $space->id,
            'membership_id' => $membership->id,
            'body' => trim($payload['body']),
            'status' => 'active',
            'grid_key' => $display['gridKey'],
            'display_lat' => $display['displayLat'],
            'display_lng' => $display['displayLng'],
            'display_radius_m' => $display['displayRadiusM'],
            'expires_at' => now()->addMinutes($space->whisper_ttl_minutes),
            'report_count' => 0,
        ]);
        $whisper->load('space');

        return $this->ok([
            'whisper' => ApiResource::whisper($whisper),
        ], 201);
    }

    public function report(Request $request, MapWhisper $whisper, MembershipGuard $guard): JsonResponse
    {
        $payload = $request->validate([
            'reasonType' => ['required', 'in:spam,harassment,privacy_risk,inappropriate,other'],
            'detail' => ['nullable', 'string', 'max:1000'],
        ]);

        $whisper->loadMissing(['space']);
        if ($whisper->expires_at <= now()) {
            $whisper->forceFill(['status' => 'expired'])->save();
            throw new ApiException('WHISPER_EXPIRED', '期限切れの whisper は通報できません。', 409);
        }

        $membership = $guard->requireActiveMembership($request->user(), $whisper->space);
        $existing = ContentReport::query()
            ->where('reporter_membership_id', $membership->id)
            ->where('target_type', 'whisper')
            ->where('target_id', $whisper->id)
            ->exists();

        if ($existing) {
            throw new ApiException('ALREADY_REPORTED', '同じ whisper は再通報できません。', 409);
        }

        $report = ContentReport::query()->create([
            'space_id' => $whisper->space_id,
            'reporter_membership_id' => $membership->id,
            'target_type' => 'whisper',
            'target_id' => $whisper->id,
            'reason_type' => $payload['reasonType'],
            'detail' => $payload['detail'] ?? null,
            'status' => 'open',
        ]);

        $whisper->increment('report_count');
        $whisper->refresh();
        if ($whisper->report_count >= $whisper->space->whisper_auto_hide_report_threshold) {
            $whisper->forceFill([
                'status' => 'hidden_by_report',
                'hidden_at' => now(),
            ])->save();
        }
        $whisper->refresh();

        return $this->ok([
            'report' => ApiResource::report($report->fresh()),
            'whisper' => ApiResource::whisper($whisper),
        ], 201);
    }

    private function assertNotMuted($membership): void
    {
        if ($membership->mute_until && $membership->mute_until->isFuture()) {
            throw new ApiException('FORBIDDEN', 'ミュート中のため whisper を投稿できません。', 403);
        }
    }

    private function assertWithinRateLimit($membership, Space $space): void
    {
        $minuteCount = MapWhisper::query()
            ->where('membership_id', $membership->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($minuteCount >= $space->whisper_rate_limit_per_minute) {
            throw new ApiException('WHISPER_RATE_LIMITED', '短時間での投稿が多すぎます。', 429);
        }

        $tenMinuteCount = MapWhisper::query()
            ->where('membership_id', $membership->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($tenMinuteCount >= $space->whisper_rate_limit_per_10min) {
            throw new ApiException('WHISPER_RATE_LIMITED', '10 分あたりの投稿上限に達しました。', 429);
        }
    }
}
