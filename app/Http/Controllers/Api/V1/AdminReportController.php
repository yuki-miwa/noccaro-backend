<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\SpaceMembership;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminReportController extends ApiController
{
    public function resolve(
        Request $request,
        ContentReport $report,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): JsonResponse {
        $actor = $guard->actorForReport($request->user(), $report);
        $payload = $request->validate([
            'resolutionType' => ['required', Rule::in(['no_action', 'content_removed', 'mute', 'kick', 'suspend', 'ban'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $report, $payload, $guard, $logger): void {
            $report->loadMissing(['space', 'reporterMembership.user']);
            $report->forceFill([
                'status' => 'resolved',
                'handled_by_membership_id' => $actor->id,
                'handled_at' => now(),
                'resolution_type' => $payload['resolutionType'],
            ])->save();

            $targetMembership = $this->targetMembership($report);
            $targetWhisper = $report->target_type === 'whisper' ? MapWhisper::query()->find($report->target_id) : null;

            switch ($payload['resolutionType']) {
                case 'content_removed':
                    if ($targetWhisper) {
                        $targetWhisper->forceFill([
                            'status' => 'removed_by_owner',
                            'removed_at' => now(),
                            'removed_by_membership_id' => $actor->id,
                        ])->save();
                    }
                    if ($targetMembership) {
                        $logger->log($actor, $targetMembership, 'remove_whisper', $payload['note'] ?? null, $report);
                    }
                    break;
                case 'mute':
                    if (! $targetMembership) {
                        break;
                    }
                    $targetMembership->forceFill([
                        'mute_until' => now()->addDay(),
                    ])->save();
                    $logger->log($actor, $targetMembership, 'mute', $payload['note'] ?? null, $report, now()->addDay()->toIso8601String());
                    break;
                case 'kick':
                    if (! $targetMembership) {
                        break;
                    }
                    $this->assertTargetMutable($targetMembership);
                    $targetMembership->forceFill([
                        'status' => 'kicked',
                        'kicked_at' => now(),
                    ])->save();
                    $logger->log($actor, $targetMembership, 'kick', $payload['note'] ?? null, $report);
                    break;
                case 'suspend':
                    if (! $targetMembership) {
                        break;
                    }
                    $this->assertTargetMutable($targetMembership);
                    $targetMembership->forceFill([
                        'status' => 'suspended',
                        'suspended_until' => now()->addDay(),
                    ])->save();
                    $logger->log($actor, $targetMembership, 'suspend', $payload['note'] ?? null, $report, now()->addDay()->toIso8601String());
                    break;
                case 'ban':
                    if (! $targetMembership) {
                        break;
                    }
                    $guard->requirePrimaryOwner($actor);
                    $this->assertTargetMutable($targetMembership);
                    $targetMembership->forceFill([
                        'status' => 'banned',
                        'banned_at' => now(),
                    ])->save();
                    $logger->log($actor, $targetMembership, 'ban', $payload['note'] ?? null, $report);
                    break;
                default:
                    break;
            }
        });

        $freshReport = $report->fresh(['space', 'reporterMembership.user', 'handledByMembership']);
        $payload = [
            'report' => ApiResource::report($freshReport),
        ];

        if ($freshReport->target_type === 'whisper') {
            $whisper = MapWhisper::query()->with(['space', 'membership'])->find($freshReport->target_id);
            if ($whisper) {
                $payload['whisper'] = ApiResource::whisper($whisper);
            }
        }

        return $this->ok($payload);
    }

    private function targetMembership(ContentReport $report): ?SpaceMembership
    {
        if ($report->target_type === 'member') {
            return SpaceMembership::query()->find($report->target_id);
        }

        if ($report->target_type === 'whisper') {
            return MapWhisper::query()->with('membership')->find($report->target_id)?->membership;
        }

        return null;
    }

    private function assertTargetMutable(SpaceMembership $membership): void
    {
        if ($membership->role === 'primary_owner') {
            throw new ApiException('CONFLICT', 'active primary_owner はこの操作の対象にできません。', 409);
        }
    }
}
