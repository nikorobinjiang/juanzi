<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WechatBindController;
use App\Http\Controllers\WechatServerController;
use Illuminate\Support\Facades\Route;

// 微信绑定页：免登录，唯一的凭据是网页授权拿到的 openid（/wechat/names 会校验节流）
Route::prefix('wechat')->group(function () {
    Route::get('/bind', [WechatBindController::class, 'show'])->name('wechat.bind');
    Route::post('/bind', [WechatBindController::class, 'submit'])->name('wechat.bind.store');
    Route::post('/unbind', [WechatBindController::class, 'unbind'])->name('wechat.unbind');
    Route::get('/names', [WechatBindController::class, 'names'])->name('wechat.names');

    // 服务号「服务器配置」回调：GET 校验签名，POST 接收消息（当前只回 success）
    Route::get('/server', [WechatServerController::class, 'verify'])->name('wechat.server.verify');
    Route::post('/server', [WechatServerController::class, 'handle'])->name('wechat.server.handle');
});

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
    // 首页（两大入口：约课聊天 / 数据概览）
    Route::get('/', function () {
        return view('home');
    });

    // 约课页（保留旧直达链接）
    Route::get('/appoints', function () {
        return view('chat');
    });

    // 数据概览页
    Route::get('/overview', function () {
        return view('overview');
    });

    // 独立图片生成页
    Route::get('/generate', function () {
        return view('generate');
    });

    // 后台管理控制台（仅总管理员 / 机构管理员，其余由中间件挡回首页）
    Route::middleware('admin')->group(function () {
        Route::get('/admin', [AdminController::class, 'index']);
    });

    // 登出
    Route::post('/logout', [AuthController::class, 'logout']);
});
