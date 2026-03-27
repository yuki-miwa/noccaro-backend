<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\AdminMembershipController;
use App\Http\Controllers\Api\V1\AdminLiveScheduleController;
use App\Http\Controllers\Api\V1\AdminPostController;
use App\Http\Controllers\Api\V1\AdminReportController;
use App\Http\Controllers\Api\V1\AdminSpaceController;
use App\Http\Controllers\Api\V1\AdminWhisperController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\LiveChatController;
use App\Http\Controllers\Api\V1\LiveStreamController;
use App\Http\Controllers\Api\V1\LiveThreadController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SpaceCreationRequestController;
use App\Http\Controllers\Api\V1\SystemAdminAuditController;
use App\Http\Controllers\Api\V1\SystemAdminAuthController;
use App\Http\Controllers\Api\V1\SystemAdminDashboardController;
use App\Http\Controllers\Api\V1\SystemAdminLiveController;
use App\Http\Controllers\Api\V1\SystemAdminPostController;
use App\Http\Controllers\Api\V1\SystemAdminReportController;
use App\Http\Controllers\Api\V1\SystemAdminSpaceController;
use App\Http\Controllers\Api\V1\SystemAdminSpaceCreationRequestController;
use App\Http\Controllers\Api\V1\SystemAdminUserController;
use App\Http\Controllers\Api\V1\WhisperController;
use App\Http\Controllers\Api\V1\WhisperImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/system-admin/auth/login', [SystemAdminAuthController::class, 'login']);
    Route::get('/whispers/{whisper}/image/{variant}', [WhisperImageController::class, 'show'])
        ->middleware('signed')
        ->name('api.v1.whispers.image');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);

        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me/profile', [MeController::class, 'updateProfile']);
        Route::get('/me/notification-settings', [MeController::class, 'notificationSettings']);
        Route::put('/me/notification-settings', [MeController::class, 'updateNotificationSettings']);

        Route::get('/spaces/joined', [SpaceController::class, 'joined']);
        Route::get('/spaces/creation-requests', [SpaceCreationRequestController::class, 'index']);
        Route::post('/spaces/creation-requests', [SpaceCreationRequestController::class, 'store']);
        Route::get('/spaces/creation-requests/{creationRequest}', [SpaceCreationRequestController::class, 'show']);
        Route::post('/spaces/join', [SpaceController::class, 'join']);
        Route::get('/spaces/{space}', [SpaceController::class, 'show']);
        Route::get('/spaces/{space}/membership', [SpaceController::class, 'membership']);
        Route::get('/spaces/{space}/posts', [PostController::class, 'index']);
        Route::get('/spaces/{space}/live-thread', [LiveThreadController::class, 'show']);
        Route::post('/spaces/{space}/live-thread/start', [LiveThreadController::class, 'start']);
        Route::post('/spaces/{space}/live-thread/close', [LiveThreadController::class, 'close']);
        Route::get('/spaces/{space}/live-stream', [LiveStreamController::class, 'show']);
        Route::post('/spaces/{space}/live-stream/start', [LiveStreamController::class, 'start']);
        Route::post('/spaces/{space}/live-stream/end', [LiveStreamController::class, 'end']);
        Route::post('/spaces/{space}/live-chat/token', [LiveChatController::class, 'issueToken']);
        Route::get('/spaces/{space}/whispers', [WhisperController::class, 'index']);
        Route::post('/spaces/{space}/whispers', [WhisperController::class, 'store']);

        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::put('/posts/{post}/reaction', [PostController::class, 'reaction']);
        Route::post('/posts/{post}/read', [PostController::class, 'read']);

        Route::post('/whispers/{whisper}/report', [WhisperController::class, 'report']);

        Route::post('/devices/register', [DeviceController::class, 'register']);
        Route::post('/devices/unregister', [DeviceController::class, 'unregister']);

        Route::prefix('/admin')->group(function (): void {
            Route::get('/spaces/{space}', [AdminSpaceController::class, 'show']);
            Route::patch('/spaces/{space}', [AdminSpaceController::class, 'update']);
            Route::get('/spaces/{space}/live-thread-schedule', [AdminLiveScheduleController::class, 'show']);
            Route::patch('/spaces/{space}/live-thread-schedule', [AdminLiveScheduleController::class, 'update']);
            Route::delete('/spaces/{space}/live-thread-schedule', [AdminLiveScheduleController::class, 'destroy']);
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
            Route::get('/live-threads', [SystemAdminLiveController::class, 'index']);

            Route::get('/spaces', [SystemAdminSpaceController::class, 'index']);
            Route::post('/spaces', [SystemAdminSpaceController::class, 'store']);
            Route::get('/spaces/{space}', [SystemAdminSpaceController::class, 'show']);
            Route::patch('/spaces/{space}', [SystemAdminSpaceController::class, 'update']);
            Route::post('/spaces/{space}/primary-owner', [SystemAdminSpaceController::class, 'assignPrimaryOwner']);
            Route::post('/spaces/{space}/live-thread/force-close', [SystemAdminLiveController::class, 'forceCloseThread']);
            Route::post('/spaces/{space}/live-stream/force-end', [SystemAdminLiveController::class, 'forceEndStream']);
            Route::get('/space-creation-requests', [SystemAdminSpaceCreationRequestController::class, 'index']);
            Route::post('/space-creation-requests/{creationRequest}/approve', [SystemAdminSpaceCreationRequestController::class, 'approve']);
            Route::post('/space-creation-requests/{creationRequest}/reject', [SystemAdminSpaceCreationRequestController::class, 'reject']);
            Route::get('/spaces/{space}/posts', [SystemAdminPostController::class, 'index']);
            Route::post('/spaces/{space}/posts', [SystemAdminPostController::class, 'store']);
            Route::patch('/posts/{post}', [SystemAdminPostController::class, 'update']);
            Route::post('/posts/{post}/publish', [SystemAdminPostController::class, 'publish']);
            Route::post('/posts/{post}/archive', [SystemAdminPostController::class, 'archive']);
            Route::delete('/posts/{post}', [SystemAdminPostController::class, 'destroy']);

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
