@php
    // 机构名来自 organizations 表（原 config 已废弃）
    $orgName = \App\Models\Organization::where('code', auth()->user()?->organization_code)->value('name')
        ?? auth()->user()?->organization_code ?? '';
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>约刻</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/chat.css') }}?v={{ filemtime(public_path('css/chat.css')) }}">
</head>
<body>

<div class="app">

    <!-- 顶栏 -->
    <header class="header">
        <div class="header-inner">
            <a class="back-btn" href="{{ url('/') }}" aria-label="返回首页">‹</a>
            <div class="header-avatar">💬</div>
            <div class="header-info">
                <div class="header-title">约刻</div>
                <div class="header-sub">约课 · AI · Excel</div>
            </div>
            <div class="header-user">
                <span class="user-badge" title="{{ $orgName }} · {{ auth()->user()->username }}">{{ $orgName }} · {{ auth()->user()->username }}</span>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button class="logout-btn" type="submit">退出</button>
                </form>
            </div>
        </div>
    </header>

    <!-- 消息区 -->
    <main class="messages" id="messages">
        <div class="msg system-msg" id="welcomeMsg">
            <p>嗨，我是约刻助手 🍀</p>
            <p>你可以：</p>
            <p>📅 <b>约课</b>：发送「给小明约明天上午10点」</p>
            <p>📍 <b>查场地</b>：问我「1A场地明天有空吗」（1A / 1B / 2A / 2B）</p>
            <p>📊 <b>约课表</b>：随时生成/下载最新 Excel</p>
            <p>❓ 还可以问我：小明什么时候上课？上了几节课？</p>
            <p>💳 <b>查会员</b>：会员卡剩余次数、有效期，到数据概览里查看</p>
            <p>💡 <b>数据概览</b>：点右下角 📊 打开，按姓名/手机号搜学员 · 会员 · 教练</p>
        </div>
    </main>

    <!-- 快捷功能 -->
    <div class="quick-bar">
        <button class="chip active" data-mode="chat">💬 智能约课</button>
        <button class="chip" data-mode="excel">📊 约课表</button>
    </div>

    <div class="panel" id="panelExcel" hidden>
        <div class="panel-title">约课表（按周分页签）</div>
        <div class="panel-tip">表格按周自动分页签，随时可以重新生成，防止 Excel 太大。</div>
        <button class="btn-primary btn-block" id="btnGenExcel">🔄 生成最新 Excel</button>
        <div class="weekly-list" id="weeklyList"></div>
    </div>

    <!-- 输入区 -->
    <footer class="inputbar">
        <input type="file" id="fileInput" accept="image/*" hidden>
        <button class="icon-btn" id="btnAttach" title="上传图片">📎</button>
        <div class="input-wrap">
            <textarea id="inputText" rows="1" placeholder="说点什么…（例如：给小明约明天上午10点）"></textarea>
        </div>
        <button class="send-btn" id="btnSend">发送</button>
    </footer>

    <!-- 已选图片预览条 -->
    <div class="preview-bar" id="previewBar" hidden>
        <img id="previewImg" alt="已选图片">
        <span id="previewText">已选择图片：作为约课截图识别</span>
        <button class="preview-close" id="btnClearPreview">✕</button>
    </div>

    <!-- 悬浮导航：概览 / 首页 -->
    <div class="fab-stack">
        <a class="fab-btn fab-data" href="{{ url('/overview') }}" title="数据概览" aria-label="数据概览">📊</a>
        <a class="fab-btn fab-home" href="{{ url('/') }}" title="返回首页" aria-label="返回首页">🏠</a>
    </div>

</div>

<!-- 加载动画 -->
<div class="loading" id="loading" hidden>
    <div class="loading-dots"><span></span><span></span><span></span></div>
    <div class="loading-text" id="loadingText">正在思考…</div>
</div>

<script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
</body>
</html>
