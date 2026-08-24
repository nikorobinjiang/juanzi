<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>图片生成 · 好运爆棚</title>
    <link rel="stylesheet" href="{{ asset('css/generate.css') }}?v={{ filemtime(public_path('css/generate.css')) }}">
</head>
<body>

<div class="app">

    <!-- 顶栏 -->
    <header class="header">
        <div class="header-inner">
            <a class="header-back" href="/" title="返回聊天">←</a>
            <div class="header-info">
                <div class="header-title">图片生成</div>
                <div class="header-sub">上传照片 · 选择风格 · 一键出图</div>
            </div>
        </div>
    </header>

    <!-- 主内容 -->
    <main class="content">

        <!-- 1. 选择风格 -->
        <section class="card">
            <div class="card-title">1️⃣ 选择风格模板</div>
            <div class="style-row">
                <button class="style-card" data-style="a">
                    <span class="style-thumb"><img src="{{ asset('styles/style_a.jpg') }}" alt="图A"></span>
                    <span class="style-name">图A</span>
                    <span class="style-desc">{{ config('doubao.styles.a.description') }}</span>
                </button>
                <button class="style-card" data-style="b">
                    <span class="style-thumb"><img src="{{ asset('styles/style_b.jpg') }}" alt="图B"></span>
                    <span class="style-name">图B</span>
                    <span class="style-desc">{{ config('doubao.styles.b.description') }}</span>
                </button>
            </div>
        </section>

        <!-- 2. 上传照片 -->
        <section class="card">
            <div class="card-title">2️⃣ 上传你的照片</div>
            <input type="file" id="fileInput" accept="image/*" hidden>
            <button class="upload-area" id="uploadArea">
                <span class="upload-icon">📷</span>
                <span class="upload-text" id="uploadText">点击选择照片</span>
            </button>
            <div class="preview-wrap" id="previewWrap" hidden>
                <img id="previewImg" alt="已选照片">
                <button class="preview-close" id="btnClear">✕ 重新选择</button>
            </div>
        </section>

        <!-- 3. 生成 -->
        <button class="btn-generate" id="btnGenerate" disabled>✨ 开始生成</button>

        <!-- 结果区 -->
        <section class="card" id="resultCard" hidden>
            <div class="card-title">🎉 生成结果</div>
            <img class="result-img" id="resultImg" alt="生成结果">
            <div class="result-actions">
                <a class="btn-download" id="btnDownload" href="#" download>⬇ 保存图片</a>
                <button class="btn-again" id="btnAgain">🔄 再生成一张</button>
            </div>
        </section>

        <!-- 历史记录 -->
        <section class="card" id="historyCard" hidden>
            <div class="card-title">🕘 最近生成</div>
            <div class="history-grid" id="historyGrid"></div>
        </section>

    </main>

</div>

<!-- 加载动画 -->
<div class="loading" id="loading" hidden>
    <div class="loading-dots"><span></span><span></span><span></span></div>
    <div class="loading-text" id="loadingText">正在生成图片…（约需 30-60 秒）</div>
</div>

<!-- 图片查看遮罩 -->
<div class="viewer" id="viewer" hidden>
    <img id="viewerImg" alt="预览">
</div>

<script src="{{ asset('js/generate.js') }}?v={{ filemtime(public_path('js/generate.js')) }}"></script>
</body>
</html>
