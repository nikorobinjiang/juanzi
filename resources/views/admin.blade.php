<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>后台管理 · 约刻</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body>

<div class="page">

    <!-- 顶栏 -->
    <header class="topbar">
        <a class="back-btn" href="{{ url('/') }}" aria-label="返回首页">‹</a>
        <h1>后台管理</h1>
        <span class="org-badge" id="orgBadge">—</span>
    </header>

    <!-- 机构切换（仅总管理员） -->
    <div class="org-switch" id="orgSwitch" hidden>
        <span class="org-switch-label">管理机构</span>
        <select id="orgSelect" aria-label="切换管理机构"></select>
    </div>

    <!-- Tab -->
    <nav class="tabs" id="tabs">
        <button class="tab active" data-tab="users">用户</button>
        <button class="tab" data-tab="org">机构码</button>
        <button class="tab" data-tab="directory">学员会员</button>
        <button class="tab" data-tab="coaches">教练</button>
        <span class="tab-ink" id="tabInk"></span>
    </nav>

    <!-- 搜索（机构码 Tab 下隐藏） -->
    <div class="search-zone" id="searchZone">
        <div class="searchbox">
            <span class="search-icon">🔍</span>
            <input id="searchInput" type="search" autocomplete="off" placeholder="搜索姓名 / 登录名 / 手机号">
            <button id="searchClear" class="search-clear" hidden aria-label="清空">✕</button>
        </div>

        <!-- 学员会员二级切换 -->
        <div class="subs" id="subBar" hidden>
            <button class="sub-chip active" data-sub="students">学员</button>
            <button class="sub-chip" data-sub="cards">会员卡</button>
        </div>
    </div>

    <!-- 列表 -->
    <main class="list" id="list"></main>

    <div class="state" id="stateBox" hidden>
        <div class="state-emoji" id="stateEmoji">📭</div>
        <div class="state-text" id="stateText">暂无数据</div>
    </div>

    <!-- 分页 -->
    <div class="pager" id="pager" hidden>
        <button class="pager-btn" id="prevPage">上一页</button>
        <span class="pager-info" id="pagerInfo">1 / 1</span>
        <button class="pager-btn" id="nextPage">下一页</button>
    </div>

    <!-- 悬浮新增 -->
    <button class="fab-add" id="fabAdd" hidden aria-label="新增">＋</button>

    <footer class="foot">约刻 · 后台管理</footer>
</div>

<!-- 表单弹层 -->
<div class="sheet-mask" id="sheetMask" hidden></div>
<section class="sheet" id="sheet" aria-hidden="true" hidden>
    <div class="sheet-head">
        <span class="sheet-title" id="sheetTitle">详情</span>
        <button class="sheet-close" id="sheetClose" aria-label="关闭">✕</button>
    </div>
    <div class="sheet-body" id="sheetBody"></div>
</section>

<!-- 确认弹层 -->
<div class="dialog-mask" id="dialogMask" hidden></div>
<div class="dialog" id="dialog" hidden>
    <div class="dialog-title" id="dialogTitle">确认操作</div>
    <div class="dialog-text" id="dialogText"></div>
    <label class="dialog-check" id="dialogCheckWrap" hidden>
        <input type="checkbox" id="dialogForce"> <span id="dialogCheckText">强制删除</span>
    </label>
    <div class="dialog-actions">
        <button class="dialog-btn cancel" id="dialogCancel">取消</button>
        <button class="dialog-btn danger" id="dialogOk">确认</button>
    </div>
</div>

<div class="toast" id="toast" hidden></div>

<script>window.ADMIN_BOOT = @json($boot);</script>
<script src="{{ asset('js/admin.js') }}?v={{ filemtime(public_path('js/admin.js')) }}"></script>
</body>
</html>
