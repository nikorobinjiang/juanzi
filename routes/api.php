<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\GenerateController;
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

    // Excel
    Route::get('/excel/generate', [ExcelController::class, 'generate']);
    Route::get('/excel/download/{filename}', [ExcelController::class, 'download']);
});
