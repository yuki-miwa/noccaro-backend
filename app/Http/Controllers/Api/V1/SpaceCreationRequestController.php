<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SpaceCreationRequest;
use App\Support\Api\ApiResource;
use App\Support\SpaceCreationRequests\SpaceCreationRequestService;
use App\Support\Spaces\SpaceCodeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SpaceCreationRequestController extends ApiController
{
    public function index(Request $request, SpaceCreationRequestService $service): JsonResponse
    {
        $items = SpaceCreationRequest::query()
            ->with(['approvedSpace'])
            ->where('requester_user_id', $request->user()->id)
            ->where(function ($query): void {
                $query
                    ->where('status', 'pending')
                    ->orWhere(function ($rejectedQuery): void {
                        $rejectedQuery
                            ->where('status', 'rejected')
                            ->where('rejection_visible_until', '>', now());
                    });
            })
            ->orderByDesc('updated_at')
            ->get();

        return $this->collection(
            $items
                ->filter(fn (SpaceCreationRequest $creationRequest) => $service->shouldAppearInPublicList($creationRequest))
                ->map(fn (SpaceCreationRequest $creationRequest) => ApiResource::spaceCreationRequest($creationRequest))
                ->values()
                ->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $items->count(),
            ],
        );
    }

    public function store(
        Request $request,
        SpaceCodeRegistry $codeRegistry,
    ): JsonResponse {
        $payload = $request->validate([
            'spaceName' => ['required', 'string', 'min:1', 'max:120'],
            'spaceCode' => ['required', 'string', 'min:1', 'max:20'],
            'joinPolicy' => ['required', Rule::in(['auto_approve', 'approval_required'])],
        ]);

        $hasPendingRequest = SpaceCreationRequest::query()
            ->where('requester_user_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingRequest) {
            throw new ApiException('SPACE_CREATION_REQUEST_ALREADY_PENDING', '未処理のスペース作成申請があります。', 409);
        }

        $normalizedCode = $codeRegistry->normalize($payload['spaceCode']);
        $codeRegistry->ensureAvailableForCreationRequest($normalizedCode);

        $creationRequest = SpaceCreationRequest::query()->create([
            'requester_user_id' => $request->user()->id,
            'future_primary_owner_user_id' => $request->user()->id,
            'requested_space_name' => trim($payload['spaceName']),
            'requested_space_code' => $normalizedCode,
            'requested_join_policy' => $payload['joinPolicy'],
            'status' => 'pending',
        ]);

        return $this->ok([
            'request' => ApiResource::spaceCreationRequest($creationRequest->fresh('approvedSpace')),
        ], 201);
    }

    public function show(
        Request $request,
        string $requestId,
        SpaceCreationRequestService $service,
    ): JsonResponse {
        $creationRequest = SpaceCreationRequest::query()
            ->with('approvedSpace')
            ->where('public_id', $requestId)
            ->first();

        if (! $creationRequest) {
            throw new ApiException('SPACE_CREATION_REQUEST_NOT_FOUND', 'スペース作成申請が見つかりません。', 404);
        }

        if ($creationRequest->requester_user_id !== $request->user()->id) {
            throw new ApiException('SPACE_CREATION_REQUEST_NOT_FOUND', 'スペース作成申請が見つかりません。', 404);
        }

        if (! $service->isVisibleToRequester($creationRequest)) {
            throw new ApiException('SPACE_CREATION_REQUEST_NOT_FOUND', 'スペース作成申請が見つかりません。', 404);
        }

        return $this->ok([
            'request' => ApiResource::spaceCreationRequest($creationRequest),
        ]);
    }
}
