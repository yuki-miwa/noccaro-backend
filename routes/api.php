<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\WhisperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);

        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/notification-settings', [MeController::class, 'notificationSettings']);
        Route::put('/me/notification-settings', [MeController::class, 'updateNotificationSettings']);

        Route::get('/spaces/joined', [SpaceController::class, 'joined']);
        Route::post('/spaces/join', [SpaceController::class, 'join']);
        Route::get('/spaces/{space}', [SpaceController::class, 'show']);
        Route::get('/spaces/{space}/membership', [SpaceController::class, 'membership']);
        Route::get('/spaces/{space}/posts', [PostController::class, 'index']);
        Route::get('/spaces/{space}/whispers', [WhisperController::class, 'index']);
        Route::post('/spaces/{space}/whispers', [WhisperController::class, 'store']);

        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::put('/posts/{post}/reaction', [PostController::class, 'reaction']);

        Route::post('/whispers/{whisper}/report', [WhisperController::class, 'report']);

        Route::post('/devices/register', [DeviceController::class, 'register']);
        Route::post('/devices/unregister', [DeviceController::class, 'unregister']);
    });

    Route::fallback(function () {
        throw new ApiException('RESOURCE_NOT_FOUND', 'API エンドポイントが見つかりません。', 404);
    });
});
