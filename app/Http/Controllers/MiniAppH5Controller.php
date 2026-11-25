<?php

namespace App\Http\Controllers;

use App\Services\GroupCourseService;
use App\Services\WechatService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MiniAppH5Controller extends Controller
{
    /** 公开 H5 活动页：微信内展示详情，报名动作拉起小程序 */
    public function show(
        string $shareToken,
        Request $request,
        GroupCourseService $courses,
        WechatService $wechat,
    ): View {
        $activity = $courses->detailByToken($shareToken);
        $signature = null;

        if ($wechat->configured()) {
            try {
                $signature = $wechat->jsapiSignature($request->fullUrl());
            } catch (RuntimeException $e) {
                // 公众号未配置 JS-SDK 安全域名时降级：链接仍可打开，只是不能一键拉起小程序
                $signature = null;
            }
        }

        return view('miniapp.h5-activity', [
            'activity' => $activity,
            'shareToken' => $shareToken,
            'signature' => $signature,
            'miniAppId' => (string) config('wechat.miniapp.app_id', ''),
            'h5Url' => rtrim((string) config('wechat.miniapp.h5_url', ''), '/').'/a/'.$shareToken,
        ]);
    }
}
