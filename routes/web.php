<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('chat');
});

// 独立图片生成页
Route::get('/generate', function () {
    return view('generate');
});
