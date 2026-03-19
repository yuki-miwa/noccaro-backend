<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Support\Api\ApiResource;
use App\Support\SystemAdmin\SystemAdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class SystemAdminAuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', strtolower($payload['email']))
            ->first();

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw new ApiException('UNAUTHENTICATED', 'メールアドレスまたはパスワードが正しくありません。', 401);
        }

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

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('system-admin-auth')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => ApiResource::systemAdmin($admin->fresh('user')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->noContent();
    }

    public function me(Request $request, SystemAdminGuard $guard): JsonResponse
    {
        $admin = $guard->actor($request->user())->load('user');

        return $this->ok([
            'user' => ApiResource::systemAdmin($admin),
        ]);
    }
}
