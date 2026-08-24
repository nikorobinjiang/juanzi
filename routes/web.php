<?php

use Illuminate\Support\Facades\Route;

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
