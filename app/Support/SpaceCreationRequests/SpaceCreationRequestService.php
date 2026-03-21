<?php

namespace App\Support\SpaceCreationRequests;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceCreationRequest;
use App\Models\SystemAdmin;
use App\Support\Spaces\SpaceCodeRegistry;
use App\Support\Spaces\SpaceProvisioningService;
use Illuminate\Support\Facades\DB;

class SpaceCreationRequestService
{
    public function __construct(
        private readonly SpaceCodeRegistry $codeRegistry,
        private readonly SpaceProvisioningService $spaceProvisioner,
    ) {}

    public function approve(SpaceCreationRequest $request, SystemAdmin $actor): SpaceCreationRequest
    {
        return DB::transaction(function () use ($actor, $request): SpaceCreationRequest {
            /** @var SpaceCreationRequest $lockedRequest */
            $lockedRequest = SpaceCreationRequest::query()
                ->with(['requester', 'futurePrimaryOwner'])
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($lockedRequest->status !== 'pending') {
                throw new ApiException('SPACE_CREATION_REQUEST_NOT_PENDING', 'この作成申請はすでに処理済みです。', 409);
            }

            $requester = $lockedRequest->requester;
            $futurePrimaryOwner = $lockedRequest->futurePrimaryOwner;

            if (! $requester || ! $futurePrimaryOwner) {
                throw new ApiException('SPACE_CREATION_REQUEST_NOT_FOUND', 'スペース作成申請が見つかりません。', 404);
            }

            if ($futurePrimaryOwner->status !== 'active') {
                throw new ApiException('CONFLICT', '申請者が無効なため承認できません。', 409);
            }

            $this->codeRegistry->ensureAvailableForSpace(
                $lockedRequest->requested_space_code,
                ignorePendingRequestId: $lockedRequest->id,
            );

            $payload = array_merge(
                SpaceCreationRequestConfig::defaultSpacePayload(),
                [
                    'name' => $lockedRequest->requested_space_name,
                    'spaceCode' => $lockedRequest->requested_space_code,
                    'joinPolicy' => $lockedRequest->requested_join_policy,
                ],
            );

            $created = $this->spaceProvisioner->createSpaceWithPrimaryOwner($payload, $requester, $futurePrimaryOwner);
            $space = $created['space'];

            $lockedRequest->forceFill([
                'status' => 'approved',
                'approved_space_id' => $space->id,
                'reviewed_by_system_admin_id' => $actor->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'rejected_at' => null,
                'rejection_visible_until' => null,
            ])->save();

            return $lockedRequest->fresh(['requester', 'futurePrimaryOwner', 'approvedSpace', 'reviewedBySystemAdmin.user']);
        });
    }

    public function reject(SpaceCreationRequest $request, SystemAdmin $actor): SpaceCreationRequest
    {
        return DB::transaction(function () use ($actor, $request): SpaceCreationRequest {
            /** @var SpaceCreationRequest $lockedRequest */
            $lockedRequest = SpaceCreationRequest::query()
                ->with(['requester', 'futurePrimaryOwner'])
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($lockedRequest->status !== 'pending') {
                throw new ApiException('SPACE_CREATION_REQUEST_NOT_PENDING', 'この作成申請はすでに処理済みです。', 409);
            }

            $rejectedAt = now();

            $lockedRequest->forceFill([
                'status' => 'rejected',
                'reviewed_by_system_admin_id' => $actor->id,
                'reviewed_at' => $rejectedAt,
                'approved_at' => null,
                'rejected_at' => $rejectedAt,
                'rejection_visible_until' => $rejectedAt->copy()->addHours(SpaceCreationRequestConfig::REJECTION_VISIBILITY_HOURS),
            ])->save();

            return $lockedRequest->fresh(['requester', 'futurePrimaryOwner', 'approvedSpace', 'reviewedBySystemAdmin.user']);
        });
    }

    public function isVisibleToRequester(SpaceCreationRequest $request): bool
    {
        return match ($request->status) {
            'pending', 'approved' => true,
            'rejected' => $request->rejection_visible_until?->isFuture() ?? false,
            default => false,
        };
    }

    public function shouldAppearInPublicList(SpaceCreationRequest $request): bool
    {
        return match ($request->status) {
            'pending' => true,
            'rejected' => $request->rejection_visible_until?->isFuture() ?? false,
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function buildApprovedSpaceSummary(SpaceCreationRequest $request): ?array
    {
        $space = $request->approvedSpace;

        if (! $space instanceof Space) {
            return null;
        }

        return [
            'id' => $space->public_id,
            'code' => $space->space_code,
            'name' => $space->name,
        ];
    }
}
