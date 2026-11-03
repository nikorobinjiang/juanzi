<?php

/**
 * 微信认证服务号配置
 *
 * 支撑「明日上课提醒」三件事：
 * 1. 网页授权 -> 学员/教练在绑定页静默拿到 openid（App\Services\WechatService）
 * 2. 模板消息 -> 每天定时给学员、教练推送卡片形态的上课提醒
 * 3. 每日提醒 -> config('wechat.remind') 控制时间、开关与汇总粒度
 *
 * 未配置 AppID / AppSecret / 模板 ID 时，相关能力自动降级为「不发送」，绝不会误发。
 *
 * 模板消息的关键词名称必须换成服务号后台里真实模板的关键词：
 * 公众号后台「模板消息」里的 {{xxx.DATA}} 前缀（keyword1 / thing2 / …）在 fields 里配置成
 * 「真实关键词名 => 卡片行标题」，顺序即卡片行的展示顺序。
 */
return [
    /*
     | 凭证（微信公众平台 → 设置与开发 → 基本配置）
     */
    'app_id' => env('WECHAT_APP_ID', ''),
    'secret' => env('WECHAT_APP_SECRET', ''),

    /*
     | 官方接口地址与网络超时
     */
    'api_base' => env('WECHAT_API_BASE', 'https://api.weixin.qq.com'),
    'timeout' => (int) env('WECHAT_TIMEOUT', 10),
    'connect_timeout' => (int) env('WECHAT_CONNECT_TIMEOUT', 5),

    /*
     | access_token 中控：服务端必须缓存 token，否则并发刷新会互相作废（42001）
     */
    'access_token' => [
        'cache_key' => 'wechat:access_token',
        'lock_key' => 'wechat:access_token:lock',
        'lock_seconds' => 10,
        // 微信给的 expires_in 是 7200 秒，提前过期避免边界上拿到刚失效的 token
        'refresh_margin' => 300,
    ],

    /*
     | 服务器配置（公众平台 → 设置与开发 → 基本配置 → 服务器配置）
     | 仅用于接收消息推送；上课提醒是主动推送，不依赖此处。Token 需与后台填写的一致。
     */
    'server' => [
        'token' => env('WECHAT_SERVER_TOKEN', ''),
    ],

    /*
     | 网页授权（绑定页）
     */
    'oauth' => [
        // snsapi_base 静默授权，用户无感知；需要昵称时改 snsapi_userinfo
        'scopes' => env('WECHAT_OAUTH_SCOPES', 'snsapi_base'),
        'redirect' => '/wechat/bind',
        'session_key' => 'wechat_openid',
    ],

    /*
     | 模板消息：fields 的 key 是模板里的真实关键词名，value 是卡片行标题
     */
    'templates' => [
        // 学员：我明天几点上课、在哪片场、哪个教练带
        'student' => [
            'id' => env('WECHAT_TEMPLATE_ID', ''),
            'url' => env('WECHAT_TEMPLATE_URL', '/wechat/bind'),
            'color' => '#173177',
            'first' => env('WECHAT_TEMPLATE_FIRST', '您好，明天有您的课程安排，别迟到了～'),
            'remark' => env('WECHAT_TEMPLATE_REMARK', '请提前 10 分钟到场，如需请假请联系教练。'),
            'fields' => [
                'keyword1' => '上课日期',
                'keyword2' => '上课时间',
                'keyword3' => '场地',
                'keyword4' => '教练',
            ],
        ],

        // 教练：明天第一节几点开始、共几节、在哪片场、带哪几个学员
        // 未单独配置 WECHAT_COACH_TEMPLATE_ID 时回退到学员模板 ID，字段仍按教练口径渲染
        'coach' => [
            'id' => env('WECHAT_COACH_TEMPLATE_ID', ''),
            'url' => env('WECHAT_COACH_TEMPLATE_URL', '/wechat/bind?role=coach'),
            'color' => '#173177',
            'first' => env('WECHAT_COACH_TEMPLATE_FIRST', '您明天有课，请提前到场准备～'),
            'remark' => env('WECHAT_COACH_TEMPLATE_REMARK', '如需调整请联系场馆前台，祝上课顺利！'),
            'fields' => [
                'keyword1' => '上课日期',
                'keyword2' => '上课时段',
                'keyword3' => '课时',
                'keyword4' => '学员',
            ],
        ],
    ],

    /*
     | 每日提醒
     */
    'remind' => [
        // 每天几点推送次日课程（Asia/Shanghai）
        'time' => env('WECHAT_REMIND_TIME', '20:00'),
        // 学员端总开关；关掉后命令完全跳过学员流水线
        'student_enabled' => env('WECHAT_REMIND_ENABLED', false),
        // 教练端总开关；独立于学员端，便于先灰度一边
        'coach_enabled' => env('WECHAT_COACH_REMIND_ENABLED', false),
        // false = 按学员汇总成一条；true = 每节课一条（教练端始终汇总）
        'per_lesson' => env('WECHAT_REMIND_PER_LESSON', false),
        // 卡片里最多列出几节课/几个学员，超出折叠成「张三 等 N 人」
        'max_items' => (int) env('WECHAT_REMIND_MAX_ITEMS', 4),
    ],

    /*
     | 绑定页节流（单 openid 每分钟上限），避免名单接口被刷
     */
    'bind' => [
        'limit_per_minute' => (int) env('WECHAT_BIND_LIMIT_PER_MINUTE', 20),
    ],
];
