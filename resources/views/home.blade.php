@php
    // 机构名来自 organizations 表
    $orgName = \App\Models\Organization::where('code', auth()->user()?->organization_code)->value('name')
        ?? auth()->user()?->organization_code ?? '';
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>约刻 · 首页</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/home.css') }}?v={{ filemtime(public_path('css/home.css')) }}">
</head>
<body>

<div class="page">
    <!-- 渐变首屏 -->
    <header class="hero">
        <div class="hero-top">
            <span class="org-chip" title="{{ $orgName }} · {{ auth()->user()->username }}">{{ $orgName }} · {{ auth()->user()->username }}</span>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button class="logout-btn" type="submit">退出</button>
            </form>
        </div>

        <div class="hero-logo">🍀</div>
        <h1 class="hero-title">约刻</h1>
        <p class="hero-sub">约课 · AI · 数据概览</p>
    </header>

    <!-- 功能入口 -->
    <main class="entries">
        <a class="entry" href="{{ url('/appoints') }}">
            <span class="entry-icon chat">💬</span>
            <span class="entry-text">
                <b>约课聊天</b>
                <i>自然语言约课 · AI 助手 · Excel 约课表</i>
            </span>
            <span class="entry-arrow">›</span>
        </a>

        <a class="entry" href="{{ url('/overview') }}">
            <span class="entry-icon data">📊</span>
            <span class="entry-text">
                <b>数据概览</b>
                <i>学员 · 会员 · 教练，一页看清</i>
            </span>
            <span class="entry-arrow">›</span>
        </a>
    </main>

    <footer class="foot">约刻 · 让场馆管理更简单<span class="icp">浙ICP备2026071731号-2</span></footer>
</div>

</body>
</html>
