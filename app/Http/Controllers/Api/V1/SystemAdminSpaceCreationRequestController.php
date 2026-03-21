<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SpaceCreationRequest;
use App\Support\Api\ApiResource;
use App\Support\SpaceCreationRequests\SpaceCreationRequestService;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAdminSpaceCreationRequestController extends ApiController
{
    public function index(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = SpaceCreationRequest::query()->with([
            'requester',
            'futurePrimaryOwner',
            'approvedSpace',
            'reviewedBySystemAdmin.user',
        ]);

        $status = $request->string('status')->value();
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->value());
            $query->where(function ($innerQuery) use ($search): void {
                $innerQuery
                    ->where('requested_space_name', 'like', '%'.$search.'%')
                    ->orWhere('requested_space_code', 'like', '%'.$search.'%')
                    ->orWhereHas('requester', function ($requesterQuery) use ($search): void {
                        $requesterQuery
                            ->where('display_name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        $items = $query->orderByDesc('created_at')->limit($limit)->get();

        return $this->collection(
            $items->map(fn (SpaceCreationRequest $creationRequest) => ApiResource::systemSpaceCreationRequest($creationRequest))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function approve(
        Request $request,
        string $requestId,
        SystemAdminGuard $guard,
        SpaceCreationRequestService $service,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $creationRequest = $this->findCreationRequest($requestId);
        $approvedRequest = $service->approve($creationRequest, $actor);
        $createdSpace = $approvedRequest->approvedSpace?->fresh(['memberships.user', 'reports']);

        $logger->log(
            $actor,
            'space_creation_request_approved',
            'space_creation_request',
            $approvedRequest->public_id,
            sprintf('%s (%s) のスペース作成申請を承認しました。', $approvedRequest->requested_space_name, $approvedRequest->requested_space_code).
                (empty($payload['note']) ? '' : ' ('.$payload['note'].')'),
            [
                'requestId' => $approvedRequest->public_id,
                'spaceId' => $approvedRequest->approvedSpace?->public_id,
                'requesterUserId' => $approvedRequest->requester?->public_id,
            ],
        );

        return $this->ok([
            'request' => ApiResource::systemSpaceCreationRequest($approvedRequest),
            'space' => $createdSpace ? ApiResource::systemSpaceSummary($createdSpace) : null,
        ]);
    }

    public function reject(
        Request $request,
        string $requestId,
        SystemAdminGuard $guard,
        SpaceCreationRequestService $service,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $creationRequest = $this->findCreationRequest($requestId);
        $rejectedRequest = $service->reject($creationRequest, $actor);

        $logger->log(
            $actor,
            'space_creation_request_rejected',
            'space_creation_request',
            $rejectedRequest->public_id,
            sprintf('%s (%s) のスペース作成申請を棄却しました。', $rejectedRequest->requested_space_name, $rejectedRequest->requested_space_code).
                (empty($payload['note']) ? '' : ' ('.$payload['note'].')'),
            [
                'requestId' => $rejectedRequest->public_id,
                'requesterUserId' => $rejectedRequest->requester?->public_id,
                'rejectionVisibleUntil' => $rejectedRequest->rejection_visible_until?->toIso8601String(),
            ],
        );

        return $this->ok([
            'request' => ApiResource::systemSpaceCreationRequest($rejectedRequest),
        ]);
    }

    private function findCreationRequest(string $requestId): SpaceCreationRequest
    {
        $creationRequest = SpaceCreationRequest::query()
            ->with([
                'requester',
                'futurePrimaryOwner',
                'approvedSpace',
                'reviewedBySystemAdmin.user',
            ])
            ->where('public_id', $requestId)
            ->first();

        if (! $creationRequest) {
            throw new ApiException('SPACE_CREATION_REQUEST_NOT_FOUND', 'スペース作成申請が見つかりません。', 404);
        }

        return $creationRequest;
    }
}
