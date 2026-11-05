<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 服务号「服务器配置」回调
 *
 * 三件事：
 * 1. GET  -> 微信保存配置时的签名校验，原样回显 echostr
 * 2. POST -> 用户消息 / 事件推送；关注（subscribe）事件记录 openid，并回复欢迎语 + 绑定链接，
 *            其余一律回 success（表示不回复），避免 5 秒未响应被微信重试骚扰。
 * 3. openid 关联：学员 / 教练与 openid 的正式关联由 /wechat/bind 绑定页（网页授权）承担；
 *    这里只负责「关注即拿到 openid」，管理员 openid 由此回填 .env 的 WECHAT_ADMIN_OPENID。
 *
 * 说明：上课提醒靠「模板消息主动推送」，不依赖本接口的回复能力。
 * 被动回复仅支持明文模式；安全模式下密文无法解析，统一回 success 不回复。
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
     * 验签通过后用 simplexml 解析明文 XML：
     * - subscribe 事件：记录 openid 到日志，并被动回复欢迎语与绑定页链接
     * - unsubscribe 事件：仅记录日志
     * - 其余消息 / 解析失败：统一回 success，微信收到即认为已处理，不再重试
     */
    public function handle(Request $request): Response
    {
        $token = $this->token();

        // 明文与安全模式都会带 signature，安全模式额外带 msg_signature（密文需解密，这里用不到）
        if (! $this->signatureValid($request, $token)) {
            Log::warning('微信消息推送签名校验失败', ['ip' => $request->ip()]);

            return response('invalid signature', 403);
        }

        $message = $this->parseMessage($request->getContent());

        if ($message !== null
            && (string) ($message->MsgType ?? '') === 'event'
            && ($event = strtolower(trim((string) ($message->Event ?? '')))) !== '') {
            $openid = trim((string) ($message->FromUserName ?? ''));
            $account = trim((string) ($message->ToUserName ?? ''));

            if ($event === 'subscribe' && $openid !== '') {
                Log::info('微信用户关注，openid 已捕获', ['openid' => $openid]);

                return response($this->textReply($openid, $account, $this->welcomeText()), 200)
                    ->header('Content-Type', 'text/plain');
            }

            if ($event === 'unsubscribe' && $openid !== '') {
                Log::info('微信用户取消关注', ['openid' => $openid]);
            }
        }

        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * 解析微信推送的明文 XML；空体或解析失败返回 null（安全模式密文会走这里，回 success 兜底）
     */
    private function parseMessage(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }

        $saved = libxml_use_internal_errors(true);

        try {
            $message = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        } catch (\Throwable) {
            $message = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($saved);
        }

        return $message === false ? null : $message;
    }

    /**
     * 关注欢迎语：附上学员 / 教练两个绑定页链接（链接由 APP_URL 生成）
     */
    private function welcomeText(): string
    {
        return "欢迎关注约刻！\n"
            ."点击链接绑定身份，即可收到明天的上课提醒：\n"
            .'学员绑定：'.url('/wechat/bind')."\n"
            .'教练绑定：'.url('/wechat/bind?role=coach');
    }

    /**
     * 被动回复文本消息 XML：FromUserName 必须是公众号原始 ID（即推送 XML 里的 ToUserName），
     * 字段顺序与转义按官方文档约定
     */
    private function textReply(string $openid, string $account, string $content): string
    {
        return sprintf(
            '<xml><ToUserName><![CDATA[%s]]></ToUserName><FromUserName><![CDATA[%s]]></FromUserName><CreateTime>%d</CreateTime><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[%s]]></Content></xml>',
            $openid,
            $account,
            time(),
            $content
        );
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
