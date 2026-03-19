<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SpaceMembership;
use App\Support\Admin\MemberActionLogger;
use App\Support\Admin\SpaceAdminGuard;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminMembershipController extends ApiController
{
    public function approve(
        Request $request,
        SpaceMembership $membership,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): JsonResponse {
        $actor = $guard->actorForMembership($request->user(), $membership);
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $membership, $payload, $logger): void {
            $membership->loadMissing(['space', 'user']);
            if ($membership->status !== 'active') {
                $membership->forceFill([
                    'status' => 'active',
                    'approved_at' => now(),
                    'approved_by_membership_id' => $actor->id,
                    'joined_at' => $membership->joined_at ?? now(),
                    'left_at' => null,
                    'kicked_at' => null,
                    'banned_at' => null,
                    'suspended_until' => null,
                ])->save();

                $logger->log($actor, $membership, 'approve', $payload['note'] ?? null);
            }
        });

        $membership->load(['space', 'user']);

        return $this->ok([
            'membership' => ApiResource::membership($membership->fresh(['space', 'user'])),
        ]);
    }

    public function reject(
        Request $request,
        SpaceMembership $membership,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): JsonResponse {
        $actor = $guard->actorForMembership($request->user(), $membership);
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $membership, $payload, $logger): void {
            $membership->loadMissing(['space', 'user']);
            $membership->forceFill([
                'status' => 'rejected',
                'left_at' => now(),
                'approved_by_membership_id' => null,
                'approved_at' => null,
                'joined_at' => null,
            ])->save();

            $logger->log($actor, $membership, 'reject', $payload['note'] ?? null);
        });

        return $this->ok([
            'membership' => ApiResource::membership($membership->fresh(['space', 'user'])),
        ]);
    }

    public function update(
        Request $request,
        SpaceMembership $membership,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): JsonResponse {
        $actor = $guard->actorForMembership($request->user(), $membership);
        $payload = $request->validate([
            'role' => ['sometimes', Rule::in(['guest', 'owner', 'primary_owner'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'kicked', 'banned', 'left'])],
            'suspendedUntil' => ['nullable', 'date', 'after:now'],
            'muteUntil' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $membership, $payload, $guard, $logger): void {
            $membership->loadMissing(['space', 'user']);
            $originalRole = $membership->role;
            $originalStatus = $membership->status;
            $originalMuteUntil = $membership->mute_until;
            $space = $membership->space;

            if (($payload['role'] ?? null) === 'primary_owner') {
                $guard->requirePrimaryOwner($actor);
                if ($membership->status !== 'active' && ($payload['status'] ?? null) !== 'active') {
                    throw new ApiException('CONFLICT', '主オーナーは active メンバーにのみ付与できます。', 409);
                }

                $currentPrimary = SpaceMembership::query()
                    ->where('space_id', $space->id)
                    ->where('role', 'primary_owner')
                    ->where('status', 'active')
                    ->first();

                if ($currentPrimary && $currentPrimary->id !== $membership->id) {
                    $currentPrimary->forceFill(['role' => 'owner'])->save();
                }

                $membership->forceFill([
                    'role' => 'primary_owner',
                    'status' => 'active',
                    'joined_at' => $membership->joined_at ?? now(),
                    'approved_at' => $membership->approved_at ?? now(),
                ]);
                $logger->log($actor, $membership, 'transfer_primary_owner', $payload['reason'] ?? null);
            } elseif (array_key_exists('role', $payload)) {
                if ($membership->role === 'primary_owner') {
                    throw new ApiException('CONFLICT', '主オーナーのロール変更は transfer のみ許可します。', 409);
                }

                if ($payload['role'] === 'owner' && $membership->status === 'active') {
                    $this->assertOwnerCapacity($space->id, $space->max_owner_count, $membership);
                }

                if ($originalRole !== $payload['role']) {
                    if ($originalRole === 'guest' && $payload['role'] === 'owner') {
                        $logger->log($actor, $membership, 'grant_owner', $payload['reason'] ?? null);
                    }
                    if ($originalRole === 'owner' && $payload['role'] === 'guest') {
                        $logger->log($actor, $membership, 'revoke_owner', $payload['reason'] ?? null);
                    }
                }

                $membership->role = $payload['role'];
            }

            if (array_key_exists('status', $payload)) {
                $this->applyStatusChange($actor, $membership, $payload['status'], $payload['suspendedUntil'] ?? null, $payload['reason'] ?? null, $guard, $logger);
            }

            if (array_key_exists('muteUntil', $payload)) {
                $membership->mute_until = $payload['muteUntil'];
                if ($payload['muteUntil']) {
                    $logger->log($actor, $membership, 'mute', $payload['reason'] ?? null, null, $payload['muteUntil']);
                } elseif ($originalMuteUntil) {
                    $logger->log($actor, $membership, 'unmute', $payload['reason'] ?? null);
                }
            }

            if ($membership->role === 'owner' && $membership->status === 'active') {
                $this->assertOwnerCapacity($space->id, $space->max_owner_count, $membership);
            }

            $membership->save();

            if ($originalStatus === 'banned' && $membership->status === 'active') {
                $logger->log($actor, $membership, 'unban', $payload['reason'] ?? null);
            }
            if ($originalStatus === 'suspended' && $membership->status === 'active') {
                $logger->log($actor, $membership, 'unsuspend', $payload['reason'] ?? null);
            }
        });

        return $this->ok([
            'membership' => ApiResource::membership($membership->fresh(['space', 'user'])),
        ]);
    }

    private function applyStatusChange(
        SpaceMembership $actor,
        SpaceMembership $membership,
        string $status,
        ?string $suspendedUntil,
        ?string $reason,
        SpaceAdminGuard $guard,
        MemberActionLogger $logger,
    ): void {
        if ($membership->role === 'primary_owner' && $status !== 'active') {
            throw new ApiException('CONFLICT', 'active primary_owner を 0 人にはできません。', 409);
        }

        if ($status === 'banned') {
            $guard->requirePrimaryOwner($actor);
        }

        if ($status === 'suspended' && ! $suspendedUntil) {
            throw new ApiException('VALIDATION_ERROR', 'suspended の場合は suspendedUntil が必要です。', 422, ['field' => 'suspendedUntil']);
        }

        if ($status === 'active') {
            $membership->forceFill([
                'status' => 'active',
                'left_at' => null,
                'kicked_at' => null,
                'banned_at' => null,
                'suspended_until' => null,
            ]);

            return;
        }

        if ($status === 'suspended') {
            $membership->forceFill([
                'status' => 'suspended',
                'suspended_until' => $suspendedUntil,
            ]);
            $logger->log($actor, $membership, 'suspend', $reason, null, $suspendedUntil);

            return;
        }

        if ($status === 'kicked') {
            $membership->forceFill([
                'status' => 'kicked',
                'kicked_at' => now(),
                'suspended_until' => null,
            ]);
            $logger->log($actor, $membership, 'kick', $reason);

            return;
        }

        if ($status === 'banned') {
            $membership->forceFill([
                'status' => 'banned',
                'banned_at' => now(),
                'suspended_until' => null,
            ]);
            $logger->log($actor, $membership, 'ban', $reason);

            return;
        }

        if ($status === 'left') {
            $membership->forceFill([
                'status' => 'left',
                'left_at' => now(),
                'suspended_until' => null,
            ]);
            $logger->log($actor, $membership, 'mark_left', $reason);
        }
    }

    private function assertOwnerCapacity(int $spaceId, int $maxOwnerCount, SpaceMembership $target): void
    {
        $activeOwnerCount = SpaceMembership::query()
            ->where('space_id', $spaceId)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->when($target->exists, fn ($query) => $query->where('id', '!=', $target->id))
            ->count();

        $willBeCounted = $target->role === 'owner' && $target->status === 'active';
        if ($willBeCounted && $activeOwnerCount >= $maxOwnerCount) {
            throw new ApiException('CONFLICT', 'owner 上限を超えるため更新できません。', 409);
        }
    }
}
