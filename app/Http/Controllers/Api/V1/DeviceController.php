<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UserPushDevice;
use App\Support\Api\ApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends ApiController
{
    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'pushToken' => ['required', 'string'],
            'deviceUuid' => ['nullable', 'string', 'max:128'],
            'appVersion' => ['nullable', 'string', 'max:50'],
            'osVersion' => ['nullable', 'string', 'max:50'],
        ]);

        $device = UserPushDevice::query()->updateOrCreate(
            [
                'platform' => $payload['platform'],
                'push_token' => $payload['pushToken'],
            ],
            [
                'user_id' => $request->user()->id,
                'device_uuid' => $payload['deviceUuid'] ?? null,
                'app_version' => $payload['appVersion'] ?? null,
                'os_version' => $payload['osVersion'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        );

        return $this->ok([
            'device' => ApiResource::device($device->fresh()),
        ]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'pushToken' => ['required', 'string'],
        ]);

        UserPushDevice::query()
            ->where('user_id', $request->user()->id)
            ->where('push_token', $payload['pushToken'])
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        return $this->ok([
            'unregistered' => true,
        ]);
    }
}
