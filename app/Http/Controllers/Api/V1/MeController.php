<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'user' => ApiResource::user($user),
            'notificationSettings' => [
                'enabled' => $user->notifications_enabled,
            ],
        ]);
    }

    public function notificationSettings(Request $request): JsonResponse
    {
        return $this->ok([
            'enabled' => $request->user()->notifications_enabled,
        ]);
    }

    public function updateNotificationSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'notifications_enabled' => $payload['enabled'],
        ])->save();

        return $this->ok([
            'enabled' => $user->notifications_enabled,
        ]);
    }
}
