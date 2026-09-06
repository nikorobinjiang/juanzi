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
    <title>数据概览 · 约刻</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/overview.css') }}?v={{ filemtime(public_path('css/overview.css')) }}">
</head>
<body>

<div class="page">

    <!-- 顶栏 -->
    <header class="topbar">
        <a class="back-btn" href="{{ url('/') }}" aria-label="返回首页">‹</a>
        <h1>数据概览</h1>
        <span class="org-badge">{{ $orgName }} · {{ auth()->user()->username }}</span>
    </header>

    <!-- 搜索区（sticky） -->
    <div class="search-zone">
        <div class="searchbox">
            <span class="search-icon">🔍</span>
            <input id="searchInput" type="search" autocomplete="off" placeholder="搜索姓名 / 手机号">
            <button id="searchClear" class="search-clear" hidden aria-label="清空">✕</button>
        </div>

        <div class="scopes" id="scopeBar">
            <button class="scope-chip active" data-scope="all">全部</button>
            <button class="scope-chip" data-scope="students">学员</button>
            <button class="scope-chip" data-scope="members">会员</button>
            <button class="scope-chip" data-scope="coaches">教练</button>
        </div>
    </div>

    <!-- 结果区 -->
    <main class="lists" id="lists"></main>

    <!-- 空态 / 加载 -->
    <div class="state" id="stateBox" hidden>
        <div class="state-emoji" id="stateEmoji">📭</div>
        <div class="state-text" id="stateText">暂无数据</div>
    </div>

    <!-- 底部详情抽屉 -->
    <div class="sheet-mask" id="sheetMask" hidden></div>
    <section class="sheet" id="sheet" aria-hidden="true" hidden>
        <div class="sheet-head">
            <span class="sheet-title" id="sheetTitle">详情</span>
            <button class="sheet-close" id="sheetClose" aria-label="关闭">✕</button>
        </div>
        <div class="sheet-body" id="sheetBody"></div>
    </section>

    <!-- 悬浮导航：数据概览 / 首页 -->
    <div class="fab-stack">
        <a class="fab-btn fab-data" href="{{ url('/overview') }}" title="数据概览" aria-label="数据概览">📊</a>
        <a class="fab-btn fab-home" href="{{ url('/') }}" title="返回首页" aria-label="返回首页">🏠</a>
    </div>

</div>

<script src="{{ asset('js/overview.js') }}?v={{ filemtime(public_path('js/overview.js')) }}"></script>
</body>
</html>
