<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Support\Api\ApiResource;
use App\Support\SystemAdmin\SystemAdminAuditLogger;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemAdminUserController extends ApiController
{
    public function index(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());
        $limit = (int) ($request->integer('limit') ?: 50);

        $query = User::query()->with(['memberships.space']);

        if ($request->filled('status') && $request->string('status')->value() !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->value());
            $query->where(function ($userQuery) use ($search): void {
                $userQuery
                    ->where('display_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('public_id', 'like', '%'.$search.'%');
            });
        }

        $users = $query->orderBy('display_name')->limit($limit)->get();

        return $this->collection(
            $users->map(fn (User $user) => ApiResource::systemUserSummary($user))->all(),
            [
                'hasMore' => false,
                'nextCursor' => null,
                'limit' => $limit,
            ],
        );
    }

    public function update(
        Request $request,
        User $user,
        SystemAdminGuard $guard,
        SystemAdminAuditLogger $logger,
    ): JsonResponse {
        $actor = $guard->actor($request->user());

        $payload = $request->validate([
            'status' => ['required', Rule::in(['active', 'locked', 'deleted'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user->forceFill([
            'status' => $payload['status'],
        ])->save();

        $noteSuffix = empty($payload['note']) ? '' : ' ('.$payload['note'].')';
        $logger->log(
            $actor,
            'user_status_updated',
            'user',
            $user->public_id,
            sprintf('%s のステータスを %s に変更しました。%s', $user->display_name, $user->status, $noteSuffix),
            [
                'userId' => $user->public_id,
                'status' => $user->status,
            ],
        );

        return $this->ok(ApiResource::systemUserSummary($user->fresh(['memberships.space'])));
    }
}
