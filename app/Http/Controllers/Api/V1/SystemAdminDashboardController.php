<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContentReport;
use App\Models\Space;
use App\Models\User;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAdminDashboardController extends ApiController
{
    public function show(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $guard->actor($request->user());

        $spaces = Space::query()->with('memberships')->get();

        $orphanedPrimaryOwnerCount = $spaces
            ->whereNotIn('status', ['deleted'])
            ->filter(function (Space $space): bool {
                return ! $space->memberships->contains(
                    fn ($membership) => $membership->role === 'primary_owner' && $membership->status === 'active'
                );
            })
            ->count();

        return $this->ok([
            'spaceCount' => Space::query()->count(),
            'activeSpaceCount' => Space::query()->where('status', 'active')->count(),
            'userCount' => User::query()->count(),
            'lockedUserCount' => User::query()->where('status', 'locked')->count(),
            'openReportCount' => ContentReport::query()->whereIn('status', ['open', 'reviewing'])->count(),
            'orphanedPrimaryOwnerCount' => $orphanedPrimaryOwnerCount,
        ]);
    }
}
