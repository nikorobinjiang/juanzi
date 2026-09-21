<?php

use App\Http\Controllers\AdminCoachController;
use App\Http\Controllers\AdminDirectoryController;
use App\Http\Controllers\AdminOrganizationController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\OverviewController;
use Illuminate\Support\Facades\Route;

// 全部业务接口需要登录（未登录返回 401，前端统一跳转登录页）
Route::middleware('auth')->group(function () {
    // 聊天
    Route::post('/chat', [ChatController::class, 'chat']);
    Route::get('/messages', [ChatController::class, 'history']);

    // 独立图片生成（页面 /generate）
    Route::post('/generate', [GenerateController::class, 'generate']);
    Route::get('/generate/history', [GenerateController::class, 'history']);

    // 约课
    Route::get('/booking', [BookingController::class, 'index']);
    Route::post('/booking', [BookingController::class, 'store']);
    Route::put('/booking/{id}', [BookingController::class, 'update'])->whereNumber('id');
    Route::delete('/booking/{id}', [BookingController::class, 'destroy'])->whereNumber('id');
    Route::post('/booking/{id}/complete', [BookingController::class, 'complete'])->whereNumber('id');

    // 数据概览（学员/会员/教练 搜索与详情）
    Route::get('/overview/search', [OverviewController::class, 'search']);
    Route::get('/overview/students/{id}', [OverviewController::class, 'student'])->whereNumber('id');
    Route::get('/overview/member', [OverviewController::class, 'member']);

    // Excel
    Route::get('/excel/generate', [ExcelController::class, 'generate']);
    Route::get('/excel/download/{filename}', [ExcelController::class, 'download']);

    // 后台管理：admin = 总管理员与机构管理员；super.admin = 仅总管理员（跨机构能力）
    Route::middleware('admin')->prefix('admin')->group(function () {
        // 机构与机构认证码
        Route::get('/organization', [AdminOrganizationController::class, 'show']);
        Route::post('/organization/reset-auth-code', [AdminOrganizationController::class, 'resetAuthCode']);

        // 用户管理
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update'])->whereNumber('id');
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy'])->whereNumber('id');
        Route::post('/users/{id}/password', [AdminUserController::class, 'resetPassword'])->whereNumber('id');
        Route::post('/users/{id}/role', [AdminUserController::class, 'assignRole'])->whereNumber('id');

        // 学员会员管理
        Route::get('/students', [AdminDirectoryController::class, 'students']);
        Route::post('/students', [AdminDirectoryController::class, 'storeStudent']);
        Route::get('/students/{id}', [AdminDirectoryController::class, 'student'])->whereNumber('id');
        Route::put('/students/{id}', [AdminDirectoryController::class, 'updateStudent'])->whereNumber('id');
        Route::delete('/students/{id}', [AdminDirectoryController::class, 'destroyStudent'])->whereNumber('id');
        Route::get('/member-cards', [AdminDirectoryController::class, 'memberCards']);
        Route::post('/member-cards', [AdminDirectoryController::class, 'storeCard']);
        Route::put('/member-cards/{id}', [AdminDirectoryController::class, 'updateCard'])->whereNumber('id');
        Route::delete('/member-cards/{id}', [AdminDirectoryController::class, 'destroyCard'])->whereNumber('id');

        // 教练管理
        Route::get('/coaches', [AdminCoachController::class, 'index']);
        Route::post('/coaches', [AdminCoachController::class, 'store']);
        Route::put('/coaches/{id}', [AdminCoachController::class, 'update'])->whereNumber('id');
        Route::delete('/coaches/{id}', [AdminCoachController::class, 'destroy'])->whereNumber('id');
        Route::post('/coaches/{id}/active', [AdminCoachController::class, 'setActive'])->whereNumber('id');
        Route::post('/coaches/{id}/user', [AdminCoachController::class, 'linkUser'])->whereNumber('id');
        Route::delete('/coaches/{id}/user', [AdminCoachController::class, 'unlinkUser'])->whereNumber('id');

        // 仅总管理员：机构清单与切换管理机构
        Route::middleware('super.admin')->group(function () {
            Route::get('/organizations', [AdminOrganizationController::class, 'index']);
            Route::post('/switch-organization', [AdminOrganizationController::class, 'switchOrganization']);
        });
    });
});
