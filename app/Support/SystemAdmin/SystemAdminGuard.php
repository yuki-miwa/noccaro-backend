<?php

namespace App\Support\SystemAdmin;

use App\Exceptions\ApiException;
use App\Models\SystemAdmin;
use App\Models\User;

class SystemAdminGuard
{
    public function actor(User $user): SystemAdmin
    {
        $admin = SystemAdmin::query()
            ->with('user')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $admin) {
            throw new ApiException('FORBIDDEN', 'システム管理者ではありません。', 403);
        }

        if ($user->status === 'locked') {
            throw new ApiException('USER_LOCKED', 'システム管理者アカウントがロックされています。', 423);
        }

        if ($user->status === 'deleted') {
            throw new ApiException('FORBIDDEN', 'このシステム管理者アカウントは利用できません。', 403);
        }

        return $admin;
    }
}
