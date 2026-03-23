<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContentReport;
use App\Models\MapWhisper;
use App\Models\SpaceMembership;
use App\Support\Api\ApiResource;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use App\Support\Whispers\WhisperLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SystemAdminReportController extends ApiController
{
    public function index(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = ContentReport::query()->with(['space', 'reporterMembership.user']);

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('reasonType') && $request->string('reasonType')->value() !== 'all') {
            $query->where('reason_type', $request->string('reasonType')->value());
        }

        if ($request->filled('spaceId')) {
            $spaceId = $request->string('spaceId')->value();
            $query->whereHas('space', fn ($spaceQuery) => $spaceQuery->where('public_id', $spaceId));
        }

        $reports = $query->orderByDesc('created_at')->limit($limit)->get();

        return $this->collection(
            $reports->map(fn (ContentReport $report) => ApiResource::systemReportSummary($report))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function resolve(
        Request $request,
        ContentReport $report,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        WhisperLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $payload = $request->validate([
            'resolutionType' => ['required', Rule::in(['no_action', 'content_removed', 'mute', 'kick', 'suspend', 'ban'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $logger, $payload, $report, $lifecycle): void {
            $report->forceFill([
                'status' => 'resolved',
                'handled_by_membership_id' => null,
                'handled_at' => now(),
                'resolution_type' => $payload['resolutionType'],
            ])->save();

            $targetMembership = $this->targetMembership($report);
            $targetWhisper = $report->target_type === 'whisper' ? MapWhisper::query()->find($report->target_id) : null;

            switch ($payload['resolutionType']) {
                case 'content_removed':
                    if ($targetWhisper) {
                        $lifecycle->remove($targetWhisper, null, 'removed_by_owner');
                    }
                    break;
                case 'mute':
                    if ($targetMembership) {
                        $targetMembership->forceFill([
                            'mute_until' => now()->addDay(),
                        ])->save();
                    }
                    break;
                case 'kick':
                    if ($targetMembership) {
                        $targetMembership->forceFill([
                            'status' => 'kicked',
                            'kicked_at' => now(),
                        ])->save();
                    }
                    break;
                case 'suspend':
                    if ($targetMembership) {
                        $targetMembership->forceFill([
                            'status' => 'suspended',
                            'suspended_until' => now()->addDay(),
                        ])->save();
                    }
                    break;
                case 'ban':
                    if ($targetMembership) {
                        $targetMembership->forceFill([
                            'status' => 'banned',
                            'banned_at' => now(),
                        ])->save();
                    }
                    break;
                default:
                    break;
            }

            $noteSuffix = empty($payload['note']) ? '' : ' ('.$payload['note'].')';
            $logger->log(
                $actor,
                'report_resolved',
                'report',
                $report->public_id,
                sprintf('通報 %s を %s で処理しました。%s', $report->public_id, $payload['resolutionType'], $noteSuffix),
                [
                    'reportId' => $report->public_id,
                    'resolutionType' => $payload['resolutionType'],
                ],
            );
        });

        return $this->ok(ApiResource::systemReportSummary($report->fresh(['space', 'reporterMembership.user'])));
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
}
