<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\User;
use App\Support\Api\ApiResource;
use App\Support\SpaceCreationRequests\SpaceCreationRequestConfig;
use App\Support\Spaces\SpaceCodeRegistry;
use App\Support\Spaces\SpaceProvisioningService;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SystemAdminSpaceController extends ApiController
{
    public function index(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = Space::query()->with(['memberships.user', 'reports']);

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->value());
            $query->where(function ($spaceQuery) use ($search): void {
                $spaceQuery
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('space_code', 'like', '%'.$search.'%')
                    ->orWhereHas('memberships.user', function ($membershipQuery) use ($search): void {
                        $membershipQuery
                            ->where('display_name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        $spaces = $query->orderByDesc('created_at')->limit($limit)->get();

        return $this->collection(
            $spaces->map(fn (Space $space) => ApiResource::systemSpaceSummary($space))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function store(
        Request $request,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        SpaceCodeRegistry $codeRegistry,
        SpaceProvisioningService $spaceProvisioner,
    ): JsonResponse {
        $actor = $guard->actor($request->user());

        $payload = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string'],
            'spaceCode' => ['required', 'string', 'max:20'],
            'joinPolicy' => ['required', Rule::in(['auto_approve', 'approval_required'])],
            'maxOwnerCount' => ['required', 'integer', 'min:1'],
            'whisperTtlMinutes' => ['required', 'integer', 'min:1'],
            'whisperMaxLength' => ['required', 'integer', 'min:1', 'max:30'],
            'locationGridMeters' => ['required', 'integer', 'min:1'],
            'locationJitterEnabled' => ['required', 'boolean'],
            'initialPrimaryOwnerUserId' => ['required', 'uuid'],
        ]);

        $targetUser = User::query()->where('public_id', $payload['initialPrimaryOwnerUserId'])->first();
        if (! $targetUser) {
            throw new ApiException('RESOURCE_NOT_FOUND', '初期主オーナーのユーザーが見つかりません。', 404);
        }

        if ($targetUser->status !== 'active') {
            throw new ApiException('CONFLICT', 'ロック中または無効なユーザーは主オーナーに設定できません。', 409);
        }

        $payload['spaceCode'] = $codeRegistry->normalize($payload['spaceCode']);
        $codeRegistry->ensureAvailableForSpace($payload['spaceCode']);

        $defaultSpaceSettings = SpaceCreationRequestConfig::defaultSpacePayload();

        $space = DB::transaction(function () use ($actor, $defaultSpaceSettings, $logger, $payload, $spaceProvisioner, $targetUser): Space {
            $created = $spaceProvisioner->createSpaceWithPrimaryOwner(
                [
                    'name' => $payload['name'],
                    'description' => $payload['description'] ?? null,
                    'spaceCode' => $payload['spaceCode'],
                    'joinPolicy' => $payload['joinPolicy'],
                    'maxOwnerCount' => $payload['maxOwnerCount'],
                    'whisperTtlMinutes' => $payload['whisperTtlMinutes'],
                    'whisperMaxLength' => $payload['whisperMaxLength'],
                    'locationGridMeters' => $payload['locationGridMeters'],
                    'locationJitterEnabled' => $payload['locationJitterEnabled'],
                    'autoHideReportThreshold' => $defaultSpaceSettings['autoHideReportThreshold'],
                    'postLimitPerMinute' => $defaultSpaceSettings['postLimitPerMinute'],
                    'postLimitPerTenMinutes' => $defaultSpaceSettings['postLimitPerTenMinutes'],
                ],
                $actor->user,
                $targetUser,
            );
            $space = $created['space'];
            $membership = $created['primaryOwnerMembership'];

            $logger->log(
                $actor,
                'space_created',
                'space',
                $space->public_id,
                sprintf('%s を作成し、%s を主オーナーに設定しました。', $space->name, $targetUser->display_name),
                [
                    'spaceId' => $space->public_id,
                    'primaryOwnerMembershipId' => $membership->public_id,
                    'primaryOwnerUserId' => $targetUser->public_id,
                ],
            );

            return $space;
        });

        return $this->ok(ApiResource::systemSpaceSummary($space->fresh(['memberships.user', 'reports'])), 201);
    }

    public function show(Request $request, Space $space, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());

        return $this->ok(ApiResource::systemSpaceSummary($space->fresh(['memberships.user', 'reports'])));
    }

    public function update(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
        SpaceCodeRegistry $codeRegistry,
    ): JsonResponse {
        $actor = $guard->actor($request->user());

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'spaceCode' => ['sometimes', 'string', 'max:20'],
            'joinPolicy' => ['sometimes', Rule::in(['auto_approve', 'approval_required'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'archived', 'deleted'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('spaceCode', $payload)) {
            $payload['spaceCode'] = $codeRegistry->normalize($payload['spaceCode']);
            $codeRegistry->ensureAvailableForSpace($payload['spaceCode'], ignoreSpaceId: $space->id);
        }

        $space->fill([
            'name' => $payload['name'] ?? $space->name,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $space->description,
            'space_code' => $payload['spaceCode'] ?? $space->space_code,
            'join_policy' => $payload['joinPolicy'] ?? $space->join_policy,
            'status' => $payload['status'] ?? $space->status,
        ]);
        $space->save();

        $noteSuffix = empty($payload['note']) ? '' : ' ('.$payload['note'].')';
        $logger->log(
            $actor,
            'space_updated',
            'space',
            $space->public_id,
            sprintf('%s の設定を更新しました。%s%s', $space->name, '状態: '.$space->status, $noteSuffix),
            [
                'spaceId' => $space->public_id,
                'status' => $space->status,
            ],
        );

        return $this->ok(ApiResource::systemSpaceResource($space->fresh()));
    }

    public function assignPrimaryOwner(
        Request $request,
        Space $space,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());

        $payload = $request->validate([
            'userId' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetUser = User::query()->where('public_id', $payload['userId'])->first();
        if (! $targetUser) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象ユーザーが見つかりません。', 404);
        }

        if ($targetUser->status !== 'active') {
            throw new ApiException('CONFLICT', 'ロック中または無効なユーザーは主オーナーに設定できません。', 409);
        }

        DB::transaction(function () use ($actor, $logger, $payload, $space, $targetUser): void {
            $currentPrimaryOwner = SpaceMembership::query()
                ->where('space_id', $space->id)
                ->where('role', 'primary_owner')
                ->where('status', 'active')
                ->first();

            if ($currentPrimaryOwner && $currentPrimaryOwner->user_id !== $targetUser->id) {
                $currentPrimaryOwner->forceFill([
                    'role' => 'owner',
                ])->save();
            }

            $targetMembership = SpaceMembership::query()
                ->where('space_id', $space->id)
                ->where('user_id', $targetUser->id)
                ->first();

            if (! $targetMembership) {
                $targetMembership = SpaceMembership::query()->create([
                    'space_id' => $space->id,
                    'user_id' => $targetUser->id,
                    'role' => 'primary_owner',
                    'status' => 'active',
                    'joined_at' => now(),
                    'approved_at' => now(),
                    'last_seen_at' => now(),
                ]);
            } else {
                $targetMembership->forceFill([
                    'role' => 'primary_owner',
                    'status' => 'active',
                    'approved_at' => $targetMembership->approved_at ?? now(),
                    'joined_at' => $targetMembership->joined_at ?? now(),
                    'kicked_at' => null,
                    'banned_at' => null,
                    'suspended_until' => null,
                    'left_at' => null,
                ])->save();
            }

            $noteSuffix = empty($payload['note']) ? '' : ' ('.$payload['note'].')';
            $logger->log(
                $actor,
                'primary_owner_assigned',
                'membership',
                $targetMembership->public_id,
                sprintf('%s の主オーナーを %s に設定しました。%s', $space->name, $targetUser->display_name, $noteSuffix),
                [
                    'spaceId' => $space->public_id,
                    'userId' => $targetUser->public_id,
                ],
            );
        });

        return $this->ok(ApiResource::systemSpaceSummary($space->fresh(['memberships.user', 'reports'])));
    }
}
