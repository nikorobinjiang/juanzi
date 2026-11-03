<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 微信认证服务号薄客户端
 *
 * 只封装「明日上课提醒」需要的三个能力：
 * 1. access_token 中控   -> accessToken()，带缓存与并发锁
 * 2. 网页授权           -> oauthRedirectUrl() / openidFromCode()
 * 3. 模板消息发送       -> sendTemplateMessage()
 *
 * 刻意不引入 EasyWeChat：本项目只用到三个接口，自写更可控，也不增加依赖与 Laravel 版本适配风险。
 * 配置项延迟读取而不是构造时固化，保证测试里 Config::set() 之后立即生效。
 */
class WechatService
{
    /** token 过期，需要刷新后重试 */
    public const ERR_TOKEN_EXPIRED = '42001';

    /** 无效 openid / 未关注公众号：这类绑定之后都发不出去，必须停用 */
    public const ERR_INVALID_TARGET = ['40003', '43004'];

    /** 是否已配置 AppID / AppSecret */
    public function configured(): bool
    {
        return $this->appId() !== '' && $this->secret() !== '';
    }

    /* -----------------------------------------------------------------
     | 网页授权
     | ----------------------------------------------------------------- */

    /**
     * 网页授权跳转地址（带锚点，微信要求在浏览器端跳转）
     */
    public function oauthRedirectUrl(string $redirectUri, string $state = ''): string
    {
        $query = http_build_query([
            'appid' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => (string) config('wechat.oauth.scopes', 'snsapi_base'),
            'state' => $state,
        ]);

        return 'https://open.weixin.qq.com/connect/oauth2/authorize?'.$query.'#wechat_redirect';
    }

    /**
     * 用授权 code 换取 openid；失败（含用户拒绝、配置缺失、网络异常）一律返回 null
     */
    public function openidFromCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '' || ! $this->configured()) {
            return null;
        }

        try {
            $response = $this->http()->get($this->apiBase().'/sns/oauth2/access_token', [
                'appid' => $this->appId(),
                'secret' => $this->secret(),
                'code' => $code,
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('微信网页授权连接失败', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('微信网页授权接口失败', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json() ?? [];

        if (isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            Log::warning('微信网页授权返回错误', ['errcode' => $data['errcode'] ?? '', 'errmsg' => $data['errmsg'] ?? '']);

            return null;
        }

        $openid = trim((string) ($data['openid'] ?? ''));

        return $openid === '' ? null : $openid;
    }

    /* -----------------------------------------------------------------
     | access_token 中控
     | ----------------------------------------------------------------- */

    /**
     * 获取有效 access_token
     *
     * 微信要求全网同一个 token 且每日刷新次数有限：多进程同时刷新会互相作废（大面积 42001），
     * 因此这里用「缓存 + 锁 + 双检」做中控，而不是简单的 Cache::remember。
     *
     * @throws RuntimeException 未配置凭证或接口不可用时抛出，调用方按整体失败处理
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('未配置微信 AppID / AppSecret，无法调用接口');
        }

        if (! $forceRefresh) {
            $cached = $this->cachedToken();

            if ($cached !== '') {
                return $cached;
            }
        }

        $lock = Cache::lock($this->lockKey(), (int) config('wechat.access_token.lock_seconds', 10));
        $token = $lock->block(5, function () use ($forceRefresh): string {
            // 拿到锁时别的进程可能已经刷新过了，这里必须再看一次缓存
            if (! $forceRefresh) {
                $cached = $this->cachedToken();

                if ($cached !== '') {
                    return $cached;
                }
            }

            return $this->refreshAccessToken();
        });

        // 5 秒内没抢到锁：说明有进程正在刷新，等它把缓存写上即可，直接淘汰本次调用由上层重试
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('微信 access_token 刷新竞争超时，请稍后重试');
        }

        return $token;
    }

    /**
     * 读取缓存里的 token（空串表示没有）
     */
    private function cachedToken(): string
    {
        $cached = Cache::get((string) config('wechat.access_token.cache_key'));

        return is_string($cached) ? $cached : '';
    }

    /**
     * 向微信换取新 token 并写入缓存
     *
     * @throws RuntimeException
     */
    private function refreshAccessToken(): string
    {
        try {
            $response = $this->http()->get($this->apiBase().'/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid' => $this->appId(),
                'secret' => $this->secret(),
            ]);
        } catch (ConnectionException $e) {
            Log::error('微信 access_token 接口连接失败', ['error' => $e->getMessage()]);

            throw new RuntimeException('微信接口连接失败，无法获取 access_token');
        }

        $data = $response->json() ?? [];
        $token = trim((string) ($data['access_token'] ?? ''));

        if ($response->failed() || $token === '') {
            Log::error('微信 access_token 获取失败', [
                'status' => $response->status(),
                'errcode' => $data['errcode'] ?? '',
                'errmsg' => $data['errmsg'] ?? '',
            ]);

            throw new RuntimeException('微信 access_token 获取失败：'.mb_substr((string) $response->body(), 0, 200));
        }

        $expires = max(60, (int) ($data['expires_in'] ?? 7200) - (int) config('wechat.access_token.refresh_margin', 300));

        Cache::put((string) config('wechat.access_token.cache_key'), $token, now()->addSeconds($expires));

        return $token;
    }

    /* -----------------------------------------------------------------
     | 模板消息
     | ----------------------------------------------------------------- */

    /**
     * 发送模板消息（卡片）
     *
     * 网络异常与错误码都不抛异常，统一返回结构化结果，让上层逐条 continue，避免一条失败中断整批。
     *
     * @param  array<string, array{value: string, color?: string}>  $data  已包含 first / remark 的完整 data
     * @return array{ok: bool, errcode: string, errmsg: string, msgid: int|null}
     */
    public function sendTemplateMessage(string $openid, string $templateId, array $data, string $url = ''): array
    {
        if ($openid === '' || $templateId === '') {
            return $this->failure('', '缺少 openid 或模板 ID');
        }

        try {
            $token = $this->accessToken();
        } catch (RuntimeException $e) {
            return $this->failure('TOKEN', $e->getMessage());
        }

        $result = $this->postTemplateMessage($token, $openid, $templateId, $data, $url);

        // token 过期是最常见的一类失败，强刷一次再试，不要整批发不出去
        if (! $result['ok'] && $result['errcode'] === self::ERR_TOKEN_EXPIRED) {
            try {
                $this->accessToken(true);
            } catch (RuntimeException $e) {
                return $this->failure('TOKEN', $e->getMessage());
            }

            $result = $this->postTemplateMessage($this->accessToken(), $openid, $templateId, $data, $url);
        }

        return $result;
    }

    /**
     * 真正发起一次模板消息请求
     *
     * @param  array<string, array{value: string, color?: string}>  $data
     * @return array{ok: bool, errcode: string, errmsg: string, msgid: int|null}
     */
    private function postTemplateMessage(string $token, string $openid, string $templateId, array $data, string $url): array
    {
        $payload = [
            'touser' => $openid,
            'template_id' => $templateId,
            'url' => $url,
            'data' => $data,
        ];

        try {
            $response = $this->http()->post(
                $this->apiBase().'/cgi-bin/message/template/send?access_token='.$token,
                $payload
            );
        } catch (ConnectionException $e) {
            Log::warning('微信模板消息连接失败', ['error' => $e->getMessage()]);

            return $this->failure('EXCEPTION', $e->getMessage());
        }

        if ($response->failed()) {
            return $this->failure((string) $response->status(), 'HTTP '.$response->status());
        }

        $body = $response->json() ?? [];
        $errcode = trim((string) ($body['errcode'] ?? ''));

        if ($errcode !== '' && $errcode !== '0') {
            Log::warning('微信模板消息发送失败', [
                'errcode' => $errcode,
                'errmsg' => $body['errmsg'] ?? '',
            ]);

            return $this->failure($errcode, (string) ($body['errmsg'] ?? '未知错误'));
        }

        return [
            'ok' => true,
            'errcode' => '0',
            'errmsg' => 'ok',
            'msgid' => isset($body['msgid']) ? (int) $body['msgid'] : null,
        ];
    }

    /**
     * 这个错误码是否意味着该绑定已经彻底发不出去
     */
    public function isInvalidTarget(string $errcode): bool
    {
        return in_array($errcode, self::ERR_INVALID_TARGET, true);
    }

    /**
     * @return array{ok: bool, errcode: string, errmsg: string, msgid: int|null}
     */
    private function failure(string $errcode, string $errmsg): array
    {
        return ['ok' => false, 'errcode' => $errcode, 'errmsg' => $errmsg, 'msgid' => null];
    }

    /* -----------------------------------------------------------------
     | 基础配置
     | ----------------------------------------------------------------- */

    private function http(): PendingRequest
    {
        return Http::connectTimeout((int) config('wechat.connect_timeout', 5))
            ->timeout((int) config('wechat.timeout', 10))
            ->acceptJson();
    }

    private function appId(): string
    {
        return trim((string) config('wechat.app_id'));
    }

    private function secret(): string
    {
        return trim((string) config('wechat.secret'));
    }

    private function apiBase(): string
    {
        return rtrim((string) config('wechat.api_base', 'https://api.weixin.qq.com'), '/');
    }

    private function lockKey(): string
    {
        return (string) config('wechat.access_token.lock_key', 'wechat:access_token:lock');
    }
}
