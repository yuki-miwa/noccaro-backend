<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'displayName' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $user = User::query()->create([
            'email' => strtolower($payload['email']),
            'display_name' => trim($payload['displayName']),
            'password' => $payload['password'],
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('mobile-auth')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => ApiResource::user($user->fresh()),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($payload['email']))->first();
        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw new ApiException('UNAUTHENTICATED', 'メールアドレスまたはパスワードが正しくありません。', 401);
        }

        if ($user->status === 'locked') {
            throw new ApiException('USER_LOCKED', 'ユーザーがロックされています。', 423);
        }

        if ($user->status === 'deleted') {
            throw new ApiException('FORBIDDEN', 'このユーザーは利用できません。', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('mobile-auth')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => ApiResource::user($user->fresh()),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->noContent();
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('mobile-refresh')->plainTextToken;

        return $this->ok([
            'token' => $token,
        ]);
    }
}
