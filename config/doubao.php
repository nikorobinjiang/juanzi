<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 豆包 / 火山方舟 Ark API 配置
    |--------------------------------------------------------------------------
    */

    'api_key' => env('DOUBAO_API_KEY', ''),

    'base_url' => rtrim(env('DOUBAO_BASE_URL', 'https://ark.cn-beijing.volces.com/api/v3'), '/'),

    /*
    | 对话模型（智能约课助手 / 问答）
    | 可在方舟控制台创建推理接入点(ep-xxx)后直接填写 endpoint id
    */
    'chat_model' => env('DOUBAO_CHAT_MODEL', 'doubao-seed-1-6-250615'),

    /*
    | 视觉模型（识别约课聊天截图）
    */
    'vision_model' => env('DOUBAO_VISION_MODEL', 'doubao-seed-1-6-250615'),

    /*
    | 文生图 / 图生图模型（用于"生成图片"功能）
    */
    'image_model' => env('DOUBAO_IMAGE_MODEL', 'doubao-seedream-3-0-i2i-250528'),

    /*
    |--------------------------------------------------------------------------
    | 图片风格模板
    |--------------------------------------------------------------------------
    | 每个风格对应一张"风格模板图"。调用豆包时会把模板图作为参考图传入。
    | image 字段可以填：
    |   - 公网可访问的 URL（推荐，模板图放云存储或 public/）
    |   - 本地文件绝对路径（服务启动时会被自动转成 base64 传给豆包）
    */
    'styles' => [
        'a' => [
            'name' => '图A',
            'description' => '风格模板图 A 的描述',
            'image' => env('DOUBAO_STYLE_A_IMAGE', public_path('styles/style_a.jpg')),
        ],
        'b' => [
            'name' => '图B',
            'description' => '风格模板图 B 的描述',
            'image' => env('DOUBAO_STYLE_B_IMAGE', public_path('styles/style_b.jpg')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 约课规则
    |--------------------------------------------------------------------------
    */
    'booking' => [
        // 场地列表
        'venues' => ['1A', '1B', '2A', '2B'],
        // 每节课时长（分钟）
        'duration_minutes' => 60,
        // 场地每天可约的时间段（24小时制，起止）
        'hours' => ['start' => 8, 'end' => 22],
    ],

    /*
    | 调用豆包的超时时间（秒）
    | 保持小于 PHP max_execution_time，确保超时由 Guzzle 抛出可捕获的异常
    */
    'timeout' => (int) env('DOUBAO_TIMEOUT', 150),

    /*
    | 图片生成超时时间（秒）
    | 生图耗时较长（Seedream 通常 30-60 秒），单独设置更长的超时
    */
    'image_timeout' => (int) env('DOUBAO_IMAGE_TIMEOUT', 120),
];
