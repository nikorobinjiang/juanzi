<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     | 微信小程序 / H5 JS-SDK
     | ----------------------------------------------------------------- */

    /** 小程序凭证是否已配置 */
    public function miniAppConfigured(): bool
    {
        return $this->miniAppAppId() !== '' && $this->miniAppSecret() !== '';
    }

    /**
     * wx.login 的 code 换取 openid / unionid / session_key
     *
     * session_key 只在内存里返回给登录服务，绝不写日志或缓存。
     *
     * @return array{openid: string, unionid: string, session_key: string}
     */
    public function miniAppCode2Session(string $code): array
    {
        $code = trim($code);

        if ($code === '' || ! $this->miniAppConfigured()) {
            throw new RuntimeException('未配置微信小程序 AppID / AppSecret，无法完成登录');
        }

        try {
            $response = $this->http()->get($this->apiBase().'/sns/jscode2session', [
                'appid' => $this->miniAppAppId(),
                'secret' => $this->miniAppSecret(),
                'js_code' => $code,
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('微信小程序 code2session 连接失败', ['error' => $e->getMessage()]);

            throw new RuntimeException('微信登录服务暂时不可用，请稍后重试');
        }

        $data = $response->json() ?? [];

        if (isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            Log::warning('微信小程序 code2session 失败', [
                'errcode' => $data['errcode'] ?? '',
                'errmsg' => $data['errmsg'] ?? '',
            ]);

            throw new RuntimeException('微信登录失败，请稍后重试');
        }

        $openid = trim((string) ($data['openid'] ?? ''));

        if ($openid === '') {
            throw new RuntimeException('微信登录未返回 openid');
        }

        return [
            'openid' => $openid,
            'unionid' => trim((string) ($data['unionid'] ?? '')),
            'session_key' => (string) ($data['session_key'] ?? ''),
        ];
    }

    /** 手机号快速验证组件返回的 code 换手机号；失败返回 null，由调用方走手动填写兜底 */
    public function miniAppPhoneNumber(string $phoneCode): ?string
    {
        $phoneCode = trim($phoneCode);

        if ($phoneCode === '' || ! $this->miniAppConfigured()) {
            return null;
        }

        try {
            $response = $this->http()->withToken($this->miniAppAccessToken())
                ->post($this->apiBase().'/wxa/business/getuserphonenumber', ['code' => $phoneCode]);
        } catch (ConnectionException|RuntimeException $e) {
            Log::warning('微信小程序手机号接口调用失败', ['error' => $e->getMessage()]);

            return null;
        }

        $data = $response->json() ?? [];
        $errcode = (string) ($data['errcode'] ?? '0');

        if ($response->failed() || $errcode !== '0') {
            Log::warning('微信小程序手机号接口失败', ['errcode' => $errcode, 'errmsg' => $data['errmsg'] ?? '']);

            return null;
        }

        $phone = trim((string) (data_get($data, 'phone_info.phoneNumber') ?? ''));

        return $phone !== '' ? $phone : null;
    }

    /** 小程序专用 access_token：独立缓存与锁，不能复用服务号 token */
    public function miniAppAccessToken(bool $forceRefresh = false): string
    {
        if (! $this->miniAppConfigured()) {
            throw new RuntimeException('未配置微信小程序 AppID / AppSecret，无法调用接口');
        }

        if (! $forceRefresh) {
            $cached = Cache::get($this->miniAppTokenCacheKey());

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $lock = Cache::lock($this->miniAppTokenLockKey(), (int) config('wechat.miniapp.access_token.lock_seconds', 10));
        $token = $lock->block(5, function () use ($forceRefresh): string {
            if (! $forceRefresh) {
                $cached = Cache::get($this->miniAppTokenCacheKey());

                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            }

            return $this->refreshMiniAppAccessToken();
        });

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('微信 access_token 刷新竞争超时，请稍后重试');
        }

        return $token;
    }

    /**
     * 获取不限制生成数量的小程序码（二进制 PNG）
     *
     * @throws RuntimeException
     */
    public function getMiniAppQrcode(string $scene, string $page = ''): string
    {
        $payload = [
            'scene' => $scene,
            'check_path' => false,
        ];

        if ($page !== '') {
            $payload['page'] = $page;
        }

        try {
            $response = $this->http()->withToken($this->miniAppAccessToken())
                ->asJson()
                ->post($this->apiBase().'/wxa/getwxacodeunlimit', $payload);
        } catch (ConnectionException $e) {
            Log::warning('微信小程序码接口连接失败', ['error' => $e->getMessage()]);

            throw new RuntimeException('小程序码生成服务暂时不可用');
        }

        $body = $response->body();
        $trimmed = ltrim($body);

        // 成功时是 PNG 二进制；失败时微信返回 JSON
        if ($response->failed() || str_starts_with($trimmed, '{')) {
            $data = json_decode($body, true) ?: [];
            Log::warning('微信小程序码生成失败', [
                'errcode' => $data['errcode'] ?? '',
                'errmsg' => $data['errmsg'] ?? '',
            ]);

            throw new RuntimeException('小程序码生成失败，请稍后重试');
        }

        return $body;
    }

    /** 生成小程序 URL Scheme（微信外打开用）；失败返回 null */
    public function miniAppScheme(string $scene, string $page = ''): ?string
    {
        $page = $page !== '' ? $page : (string) config('wechat.miniapp.detail_page', 'pages/activity/detail/index');

        try {
            $response = $this->http()->withToken($this->miniAppAccessToken())
                ->asJson()
                ->post($this->apiBase().'/wxa/generate_scheme', [
                    'jump_wxa' => [
                        'path' => $page,
                        'query' => 's='.$scene,
                    ],
                    'expire_type' => 1,
                    'expire_interval' => 30,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('微信小程序 Scheme 接口连接失败', ['error' => $e->getMessage()]);

            return null;
        }

        $data = $response->json() ?? [];

        if ($response->failed() || (int) ($data['errcode'] ?? 0) !== 0) {
            Log::warning('微信小程序 Scheme 生成失败', [
                'errcode' => $data['errcode'] ?? '',
                'errmsg' => $data['errmsg'] ?? '',
            ]);

            return null;
        }

        $scheme = trim((string) ($data['openlink'] ?? ''));

        return $scheme !== '' ? $scheme : null;
    }

    /** H5 在微信内拉起小程序所需的 JS-SDK 签名 */
    public function jsapiSignature(string $url): array
    {
        $ticket = $this->jsapiTicket();
        $nonceStr = Str::random(16);
        $timestamp = time();
        $string = 'jsapi_ticket='.$ticket.'&noncestr='.$nonceStr.'&timestamp='.$timestamp.'&url='.$url;

        return [
            'appId' => $this->appId(),
            'timestamp' => $timestamp,
            'nonceStr' => $nonceStr,
            'signature' => sha1($string),
        ];
    }

    /** @throws RuntimeException */
    private function jsapiTicket(): string
    {
        $cached = Cache::get((string) config('wechat.jsapi_ticket.cache_key'));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock((string) config('wechat.jsapi_ticket.lock_key', 'wechat:jsapi_ticket:lock'),
            (int) config('wechat.jsapi_ticket.lock_seconds', 10));
        $ticket = $lock->block(5, function (): string {
            $cached = Cache::get((string) config('wechat.jsapi_ticket.cache_key'));

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            try {
                $response = $this->http()->get($this->apiBase().'/cgi-bin/ticket/getticket', [
                    'access_token' => $this->accessToken(),
                    'type' => 'jsapi',
                ]);
            } catch (ConnectionException $e) {
                Log::warning('微信 JS-SDK ticket 连接失败', ['error' => $e->getMessage()]);

                throw new RuntimeException('微信 JS-SDK 服务暂时不可用');
            }

            $data = $response->json() ?? [];
            $value = trim((string) ($data['ticket'] ?? ''));

            if ($response->failed() || $value === '') {
                Log::warning('微信 JS-SDK ticket 获取失败', [
                    'errcode' => $data['errcode'] ?? '',
                    'errmsg' => $data['errmsg'] ?? '',
                ]);

                throw new RuntimeException('微信 JS-SDK 签名失败');
            }

            $expires = max(60, (int) ($data['expires_in'] ?? 7200)
                - (int) config('wechat.jsapi_ticket.refresh_margin', 300));
            Cache::put((string) config('wechat.jsapi_ticket.cache_key'), $value, now()->addSeconds($expires));

            return $value;
        });

        if (! is_string($ticket) || $ticket === '') {
            throw new RuntimeException('微信 JS-SDK ticket 刷新竞争超时，请稍后重试');
        }

        return $ticket;
    }

    /** @throws RuntimeException */
    private function refreshMiniAppAccessToken(): string
    {
        try {
            $response = $this->http()->get($this->apiBase().'/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid' => $this->miniAppAppId(),
                'secret' => $this->miniAppSecret(),
            ]);
        } catch (ConnectionException $e) {
            Log::error('微信小程序 access_token 接口连接失败', ['error' => $e->getMessage()]);

            throw new RuntimeException('微信接口连接失败，无法获取 access_token');
        }

        $data = $response->json() ?? [];
        $token = trim((string) ($data['access_token'] ?? ''));

        if ($response->failed() || $token === '') {
            Log::error('微信小程序 access_token 获取失败', [
                'status' => $response->status(),
                'errcode' => $data['errcode'] ?? '',
                'errmsg' => $data['errmsg'] ?? '',
            ]);

            throw new RuntimeException('微信 access_token 获取失败');
        }

        $expires = max(60, (int) ($data['expires_in'] ?? 7200)
            - (int) config('wechat.miniapp.access_token.refresh_margin', 300));
        Cache::put($this->miniAppTokenCacheKey(), $token, now()->addSeconds($expires));

        return $token;
    }

    private function miniAppAppId(): string
    {
        return trim((string) config('wechat.miniapp.app_id'));
    }

    private function miniAppSecret(): string
    {
        return trim((string) config('wechat.miniapp.secret'));
    }

    private function miniAppTokenCacheKey(): string
    {
        return (string) config('wechat.miniapp.access_token.cache_key', 'wechat:miniapp:access_token');
    }

    private function miniAppTokenLockKey(): string
    {
        return (string) config('wechat.miniapp.access_token.lock_key', 'wechat:miniapp:access_token:lock');
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
