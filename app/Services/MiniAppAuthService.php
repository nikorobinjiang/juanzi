<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MiniAppAuthService
{
    public function __construct(private readonly WechatService $wechat) {}

    /**
     * 机构人员登录：复用现有 users 表，不另建员工体系。
     *
     * @return array{token: string, token_type: string, user: array<string, mixed>}
     */
    public function staffLogin(string $organizationCode, string $username, string $password): array
    {
        $organization = Organization::where('code', $organizationCode)->first();

        if (! $organization) {
            throw ValidationException::withMessages(['organization_code' => '机构不存在']);
        }

        $user = User::where('organization_code', $organizationCode)
            ->where('username', $username)
            ->first();

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['username' => '机构、用户名或密码不正确']);
        }

        $token = $user->createToken('miniapp-staff', ['staff'])->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'organization_code' => $user->organization_code,
                'organization_name' => $organization->name,
                'role' => $user->currentRole(),
            ],
        ];
    }

    /**
     * 客户微信登录：code2session 获取 openid，手机号授权失败时可用手动填写兜底。
     *
     * @return array{token: string, token_type: string, customer: array<string, mixed>}
     */
    public function customerLogin(
        string $code,
        ?string $phoneCode,
        ?string $fallbackPhone,
        ?string $name,
    ): array {
        try {
            $session = $this->wechat->miniAppCode2Session($code);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $phone = null;

        if ($phoneCode) {
            $phone = $this->wechat->miniAppPhoneNumber($phoneCode);
        }

        if (! $phone && $fallbackPhone) {
            $phone = trim($fallbackPhone);
        }

        if (! $phone || ! preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => '无法获取手机号，请手动填写有效的 11 位手机号']);
        }

        $customer = Customer::where('phone', $phone)->first();

        if (! $customer) {
            $customer = Customer::where('miniapp_openid', $session['openid'])->first();
        }

        if (! $customer) {
            $customer = new Customer();
            $customer->phone = $phone;
        }

        // 同一微信换绑手机号时，先把旧记录上的 openid 释放，避免唯一键冲突
        $openidOwner = Customer::where('miniapp_openid', $session['openid'])->first();

        if ($openidOwner && $openidOwner->id !== $customer->id) {
            $openidOwner->miniapp_openid = null;
            $openidOwner->save();
        }

        if ($session['unionid'] !== '') {
            $unionidOwner = Customer::where('unionid', $session['unionid'])->first();

            if ($unionidOwner && $unionidOwner->id !== $customer->id) {
                $unionidOwner->unionid = null;
                $unionidOwner->save();
            }

            $customer->unionid = $session['unionid'];
        }

        $customer->miniapp_openid = $session['openid'];

        if ($name && ! $customer->name) {
            $customer->name = trim($name);
        }

        $customer->last_login_at = now();
        $customer->save();

        $token = $customer->createToken('miniapp-customer', ['customer'])->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->maskedPhone(),
            ],
        ];
    }
}
