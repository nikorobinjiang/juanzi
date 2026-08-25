<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 未登录访问页面时跳转登录页（API 请求期望 JSON，由异常处理器直接返回 401）
        $middleware->redirectGuestsTo(fn () => route('login'));

        // API 复用 web 登录态：api 组默认没有 session 中间件，/api/* 请求读不到登录 session
        // 会永远返回 401 → 前端跳 /login → 已登录又被 guest 中间件弹回 /appoints → 页面闪刷新循环
        $middleware->api(append: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
