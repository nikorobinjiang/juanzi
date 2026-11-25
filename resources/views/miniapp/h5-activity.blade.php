<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{{ $activity['title'] }} - 活动详情</title>
    <meta name="description" content="{{ $activity['description'] ?: '一起来拼课，让每一节课都更值得' }}">
    <meta property="og:title" content="{{ $activity['title'] }} · 一起来拼课">
    <meta property="og:description" content="{{ $activity['description'] ?: '点击查看详情并报名' }}">
    @if($activity['cover_url'])
        <meta property="og:image" content="{{ $activity['cover_url'] }}">
    @endif
    <link rel="stylesheet" href="{{ asset('css/miniapp-h5.css') }}">
    <script src="https://res.wx.qq.com/open/js/jweixin-1.6.0.js"></script>
</head>
<body>
    <div class="page">
        <header class="h5-hero">
            <div class="hero-badge">YUEKE · GROUP COURSE</div>
            <h1 class="hero-title">{{ $activity['title'] }}</h1>
            <p class="hero-subtitle">{{ $activity['organization_name'] ?: '约刻拼课' }} · 一起拼课更轻松</p>
        </header>

        @if($activity['cover_url'])
            <div class="cover-wrap">
                <img class="cover" src="{{ $activity['cover_url'] }}" alt="活动封面">
            </div>
        @endif

        <section class="card info-card">
            <div class="info-row">
                <span class="info-icon venue">校</span>
                <div class="info-content">
                    <div class="info-label">校区</div>
                    <div class="info-value">{{ $activity['venue'] ?: '未填写' }}</div>
                </div>
            </div>
            <div class="info-row" id="phone-row" data-phone="{{ $activity['contact_phone'] }}">
                <span class="info-icon phone">电</span>
                <div class="info-content">
                    <div class="info-label">联系电话</div>
                    <div class="info-value phone-value">{{ $activity['contact_phone'] ?: '未填写' }}</div>
                </div>
                <span class="info-action">拨打 ›</span>
            </div>
            <div class="info-row" id="address-row" data-address="{{ $activity['address'] }}">
                <span class="info-icon address">址</span>
                <div class="info-content">
                    <div class="info-label">活动地址</div>
                    <div class="info-value">{{ $activity['address'] ?: '未填写' }}</div>
                </div>
                <span class="info-action">复制 ›</span>
            </div>
        </section>

        <section class="card signup-card">
            <div class="signup-head">
                <div class="signup-title">报名 {{ $activity['signed_up_count'] }}/{{ $activity['capacity'] }}</div>
                <span class="tag {{ $activity['remaining'] > 0 ? '' : 'full' }}">
                    {{ $activity['remaining'] > 0 ? '还剩 '.$activity['remaining'].' 个名额' : '名额已满' }}
                </span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width: {{ $activity['capacity'] > 0 ? min(100, round($activity['signed_up_count'] / $activity['capacity'] * 100)) : 0 }}%"></div>
            </div>

            <div class="signup-list">
                @forelse($activity['signups'] as $signup)
                    <div class="signup-item">
                        <div class="avatar">{{ mb_substr($signup['name'], 0, 1) }}</div>
                        <div class="signup-main">
                            <div class="signup-name">{{ $signup['name'] }}</div>
                            <div class="signup-sub">报名：{{ $signup['people_count'] }} 人</div>
                        </div>
                        <div class="recent">近期报名{{ $signup['recent_count'] }}次</div>
                    </div>
                @empty
                    <div class="empty-inline">还没有人报名，抢先加入吧</div>
                @endforelse
            </div>
        </section>

        <section class="card intro-card">
            <div class="section-title">活动介绍</div>
            <div class="intro-text">{{ $activity['description'] ?: '教练将通过系统的训练计划，帮助学员提升技术水平。' }}</div>
            @if($activity['starts_at'])
                <div class="meta-row">
                    <span class="meta-label">活动时间</span>
                    <span class="meta-value">{{ $activity['starts_at'] }} @if($activity['ends_at']) — {{ $activity['ends_at'] }} @endif</span>
                </div>
            @endif
        </section>

        <div class="h5-notice">报名需在微信小程序中完成，手机号仅用于联系，不会公开展示。</div>
        <div class="bottom-placeholder"></div>
    </div>

    <div class="bottom-bar">
        <button class="secondary-btn" id="copy-btn">复制链接</button>

        @if($miniAppId !== '')
            <wx-open-launch-weapp
                id="launch-btn"
                username="{{ $miniAppId }}"
                path="pages/activity/detail/index?s={{ $shareToken }}"
            >
                <script type="text/wxtag-template">
                    <style>
                        .launch-btn { width: 100%; height: 48px; line-height: 48px; border-radius: 24px; border: none; color: #fff; font-size: 15px; font-weight: 600; background: linear-gradient(135deg, #A78BFA, #7C3AED 55%, #6D28D9); }
                    </style>
                    <button class="launch-btn">打开小程序报名</button>
                </script>
            </wx-open-launch-weapp>
        @endif

        <button class="primary-btn" id="open-scheme">在小程序中报名</button>
    </div>

    <script id="activity-data" type="application/json" data-token="{{ $shareToken }}">@json($activity)</script>
    <script id="wx-signature" type="application/json">@json($signature)</script>
    <script>window.MINIAPP_H5_URL = @json($h5Url);</script>
    <script src="{{ asset('js/miniapp-h5.js') }}"></script>
</body>
</html>
