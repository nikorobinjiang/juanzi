<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// 未登录：登录 / 注册
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    // 机构初始化状态查询（注册页选择机构后 AJAX）
    Route::get('/organizations/{code}/status', [AuthController::class, 'organizationStatus']);
});

// 已登录：业务页面与登出
Route::middleware('auth')->group(function () {
    // 约课页
    Route::get('/appoints', function () {
        return view('chat');
    });

    // 根路径跳转到约课页（保持旧链接可用）
    Route::get('/', function () {
        return redirect('/appoints');
    });

    // 独立图片生成页
    Route::get('/generate', function () {
        return view('generate');
    });

    // 登出
    Route::post('/logout', [AuthController::class, 'logout']);
});
