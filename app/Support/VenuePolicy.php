<?php

namespace App\Support;

use App\Models\Organization;

/**
 * 机构场地模式判定：约课时到底要不要管场地
 *
 * 网球馆、羽毛球馆这类机构场地是硬资源，同一块场地同一时间只能给一节课（还要处理整场/半场拼场）；
 * 游泳馆、棋院这类机构没有严格场地概念，约课不该再分配场地、也不该报场地冲突。
 * 差异全部收敛到这里：BookingService（约课读写）与 DoubaoService（AI 提示词）都按它分支，
 * 场地互斥规则（VenueSlots）与 config('doubao.booking.*') 保持原样不动。
 *
 * 口径：
 * - 未传机构 code 时取当前登录用户的机构（业务写入只发生在登录态）
 * - 机构不存在 / 未登录 / 脏值 → 回落 required（保守：宁愿多校验，也不放掉场地冲突）
 *
 * 请求内缓存：organizations 表按 code 唯一索引查询，一节课最多查一次。
 * 后台切换模式后调用 flush() 失效缓存（同请求内立即生效）。
 */
final class VenuePolicy
{
    /** @var array<string, string> code => mode */
    private static array $cache = [];

    /**
     * 该机构的场地模式
     */
    public function modeFor(?string $orgCode = null): string
    {
        $code = trim((string) ($orgCode ?? ''));

        if ($code === '') {
            $code = (string) (auth('web')->user()?->organization_code ?? '');
        }

        if ($code === '') {
            return Organization::VENUE_MODE_REQUIRED;
        }

        if (isset(self::$cache[$code])) {
            return self::$cache[$code];
        }

        /** @var Organization|null $org */
        $org = Organization::query()->where('code', $code)->first(['venue_mode']);

        return self::$cache[$code] = $org?->venueMode() ?? Organization::VENUE_MODE_REQUIRED;
    }

    /**
     * 该机构约课时是否需要场地
     */
    public function requiresVenue(?string $orgCode = null): bool
    {
        return $this->modeFor($orgCode) === Organization::VENUE_MODE_REQUIRED;
    }

    /**
     * 清空请求内缓存（后台切换场地模式、测试切换机构时使用）
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
