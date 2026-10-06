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
    /** 按场地排课：约课必须分配场地，并做整场/半场冲突检测（网球馆、羽毛球馆等） */
    public const VENUE_MODE_REQUIRED = 'required';

    /** 不按场地排课：约课不填场地、不做场地冲突（游泳馆、棋院等） */
    public const VENUE_MODE_NONE = 'none';

    /** @var list<string> */
    public const VENUE_MODES = [self::VENUE_MODE_REQUIRED, self::VENUE_MODE_NONE];

    /** @var array<string, string> */
    public const VENUE_MODE_LABELS = [
        self::VENUE_MODE_REQUIRED => '按场地排课',
        self::VENUE_MODE_NONE => '不按场地排课',
    ];

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'auth_code', 'venue_mode'];

    /** 随机码排除易混淆字符（0/O、1/I/l），统一大写 */
    private const AUTH_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 机构是否已初始化认证码 */
    public function isInitialized(): bool
    {
        return $this->auth_code !== null && $this->auth_code !== '';
    }

    /**
     * 场地模式（脏数据兜底为 required，避免未知值让约课丢掉场地校验）
     */
    public function venueMode(): string
    {
        $mode = (string) ($this->attributes['venue_mode'] ?? '');

        return in_array($mode, self::VENUE_MODES, true) ? $mode : self::VENUE_MODE_REQUIRED;
    }

    /** 是否需要场地 */
    public function requiresVenue(): bool
    {
        return $this->venueMode() === self::VENUE_MODE_REQUIRED;
    }

    /** 场地模式中文名（后台展示用） */
    public function getVenueModeLabelAttribute(): string
    {
        return self::VENUE_MODE_LABELS[$this->venueMode()] ?? $this->venueMode();
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
