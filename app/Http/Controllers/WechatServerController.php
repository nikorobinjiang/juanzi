<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 服务号「服务器配置」回调
 *
 * 只做两件事：
 * 1. GET  -> 微信保存配置时的签名校验，原样回显 echostr
 * 2. POST -> 用户消息 / 事件推送，目前一律回 success（表示不回复），
 *            这样配置能保存成功，也不会因为 5 秒未响应被微信重试骚扰。
 *
 * 说明：上课提醒靠「模板消息主动推送」，不依赖本接口；
 * 这里存在只是为了后台「配置消息推送」表单能通过验证，后续要接关键词回复再扩展。
 */
class WechatServerController extends Controller
{
    /**
     * 微信服务器配置校验（GET）
     */
    public function verify(Request $request): Response
    {
        $token = $this->token();

        if ($token === '') {
            Log::warning('微信服务器配置未设置 Token，拒绝校验请求');

            return response('未配置 WECHAT_SERVER_TOKEN', 503);
        }

        if (! $this->signatureValid($request, $token)) {
            Log::warning('微信服务器配置签名校验失败', ['ip' => $request->ip()]);

            return response('invalid signature', 403);
        }

        $echo = (string) $request->query('echostr', '');

        // 必须原样返回纯文本，不能带引号或 JSON 包装
        return response($echo, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * 用户消息 / 事件推送（POST）
     *
     * 未接入业务逻辑前统一回 success：微信收到 success 即认为已处理，不再重试。
     * 安全模式下请求体是密文，但「不回复」只需要这段纯文本，无需解密。
     */
    public function handle(Request $request): Response
    {
        $token = $this->token();

        // 明文与安全模式都会带 signature，安全模式额外带 msg_signature（需解密才能校验，这里用不到）
        if (! $this->signatureValid($request, $token)) {
            Log::warning('微信消息推送签名校验失败', ['ip' => $request->ip()]);

            return response('invalid signature', 403);
        }

        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * signature = sha1(字典序拼接 token / timestamp / nonce)
     */
    private function signatureValid(Request $request, string $token): bool
    {
        $signature = (string) $request->query('signature', '');
        $timestamp = (string) $request->query('timestamp', '');
        $nonce = (string) $request->query('nonce', '');

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);

        return hash_equals(sha1(implode('', $parts)), $signature);
    }

    private function token(): string
    {
        return trim((string) config('wechat.server.token', ''));
    }
}
