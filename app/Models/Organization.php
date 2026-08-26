<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 机构（认证码驱动）
 *
 * 注意：本模型不加 OrganizationScope —— 机构表是全局机构清单，
 * 不能被当前登录用户的机构过滤（登录/注册页需要看到全部机构）。
 */
class Organization extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'auth_code'];

    /** 随机码排除易混淆字符（0/O、1/I/l），统一大写 */
    private const AUTH_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 机构是否已初始化认证码 */
    public function isInitialized(): bool
    {
        return $this->auth_code !== null && $this->auth_code !== '';
    }

    /** 生成 6 位大写字母数字认证码（不含易混淆字符） */
    public static function generateAuthCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::AUTH_ALPHABET[random_int(0, strlen(self::AUTH_ALPHABET) - 1)];
        }

        return $code;
    }
}
