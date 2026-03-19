<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\AdminMembershipController;
use App\Http\Controllers\Api\V1\AdminPostController;
use App\Http\Controllers\Api\V1\AdminReportController;
use App\Http\Controllers\Api\V1\AdminSpaceController;
use App\Http\Controllers\Api\V1\AdminWhisperController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SystemAdminAuditController;
use App\Http\Controllers\Api\V1\SystemAdminAuthController;
use App\Http\Controllers\Api\V1\SystemAdminDashboardController;
use App\Http\Controllers\Api\V1\SystemAdminReportController;
use App\Http\Controllers\Api\V1\SystemAdminSpaceController;
use App\Http\Controllers\Api\V1\SystemAdminUserController;
use App\Http\Controllers\Api\V1\WhisperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/system-admin/auth/login', [SystemAdminAuthController::class, 'login']);

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

        Route::prefix('/admin')->group(function (): void {
            Route::get('/spaces/{space}', [AdminSpaceController::class, 'show']);
            Route::patch('/spaces/{space}', [AdminSpaceController::class, 'update']);
            Route::get('/spaces/{space}/join-requests', [AdminSpaceController::class, 'joinRequests']);
            Route::get('/spaces/{space}/members', [AdminSpaceController::class, 'members']);
            Route::get('/spaces/{space}/posts', [AdminSpaceController::class, 'posts']);
            Route::post('/spaces/{space}/posts', [AdminSpaceController::class, 'createPost']);
            Route::get('/spaces/{space}/reports', [AdminSpaceController::class, 'reports']);
            Route::get('/spaces/{space}/notifications', [AdminSpaceController::class, 'notifications']);
            Route::post('/spaces/{space}/notifications', [AdminSpaceController::class, 'createNotification']);

            Route::post('/memberships/{membership}/approve', [AdminMembershipController::class, 'approve']);
            Route::post('/memberships/{membership}/reject', [AdminMembershipController::class, 'reject']);
            Route::patch('/memberships/{membership}', [AdminMembershipController::class, 'update']);

            Route::patch('/posts/{post}', [AdminPostController::class, 'update']);
            Route::post('/posts/{post}/publish', [AdminPostController::class, 'publish']);
            Route::post('/posts/{post}/archive', [AdminPostController::class, 'archive']);
            Route::delete('/posts/{post}', [AdminPostController::class, 'destroy']);

            Route::post('/reports/{report}/resolve', [AdminReportController::class, 'resolve']);
            Route::post('/whispers/{whisper}/remove', [AdminWhisperController::class, 'remove']);
        });

        Route::prefix('/system-admin')->group(function (): void {
            Route::post('/auth/logout', [SystemAdminAuthController::class, 'logout']);
            Route::get('/me', [SystemAdminAuthController::class, 'me']);
            Route::get('/dashboard', [SystemAdminDashboardController::class, 'show']);

            Route::get('/spaces', [SystemAdminSpaceController::class, 'index']);
            Route::post('/spaces', [SystemAdminSpaceController::class, 'store']);
            Route::get('/spaces/{space}', [SystemAdminSpaceController::class, 'show']);
            Route::patch('/spaces/{space}', [SystemAdminSpaceController::class, 'update']);
            Route::post('/spaces/{space}/primary-owner', [SystemAdminSpaceController::class, 'assignPrimaryOwner']);

            Route::get('/users', [SystemAdminUserController::class, 'index']);
            Route::patch('/users/{user}', [SystemAdminUserController::class, 'update']);

            Route::get('/reports', [SystemAdminReportController::class, 'index']);
            Route::post('/reports/{report}/resolve', [SystemAdminReportController::class, 'resolve']);

            Route::get('/audit-logs', [SystemAdminAuditController::class, 'index']);
        });
    });

    Route::fallback(function () {
        throw new ApiException('RESOURCE_NOT_FOUND', 'API エンドポイントが見つかりません。', 404);
    });
});
