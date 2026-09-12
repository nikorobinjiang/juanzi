<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * 约课时间窗口（单一事实来源）
 *
 * 两个窗口都以"周一"为一周的开始，统一使用 Asia/Shanghai 时区：
 * - 固定场窗口：本周一 00:00 起、config('doubao.fixed_schedule.weeks') 周（默认 2 周 = 本周 + 下周）
 * - 展示窗口：  本周一 00:00 起、config('doubao.window.weeks') 周（默认 4 周 = 当前周 + 未来三周）
 *
 * 约定：所有窗口都是"左闭右开"（end 为下下周一的 00:00），查询统一用
 * `start_at >= start` + `start_at < end`，避免各模块各自算"本周一 + N 周"导致口径不一致。
 */
class BookingWindow
{
    public const TIMEZONE = 'Asia/Shanghai';

    /** 当前时间（统一时区） */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /** 固定场窗口起点：默认本周一，config('doubao.fixed_schedule.start') 有值时以其所在周一为准 */
    public static function fixedStart(?Carbon $now = null): Carbon
    {
        $configured = trim((string) config('doubao.fixed_schedule.start', ''));

        if ($configured !== '') {
            try {
                return Carbon::parse($configured, self::TIMEZONE)->startOfWeek(Carbon::MONDAY)->startOfDay();
            } catch (\Throwable $e) {
                // 配置异常时退回"本周一"，不影响正常展开
            }
        }

        return self::weekStart($now);
    }

    /** 固定场窗口终点（开区间，= 固定场窗口起点 + N 周） */
    public static function fixedEnd(?Carbon $now = null): Carbon
    {
        return self::fixedStart($now)->copy()->addWeeks(self::fixedWeeks());
    }

    /** 展示窗口起点（本周一 00:00） */
    public static function displayStart(?Carbon $now = null): Carbon
    {
        return self::weekStart($now);
    }

    /** 展示窗口终点（开区间，= 展示窗口起点 + N 周） */
    public static function displayEnd(?Carbon $now = null): Carbon
    {
        return self::displayStart($now)->copy()->addWeeks(self::displayWeeks());
    }

    /** 固定场展开周数（本周 + 下周 = 2） */
    public static function fixedWeeks(): int
    {
        return max(1, (int) config('doubao.fixed_schedule.weeks', 2));
    }

    /** 约课记录展示周数（当前周 + 未来三周 = 4） */
    public static function displayWeeks(): int
    {
        return max(1, (int) config('doubao.window.weeks', 4));
    }

    /**
     * 本周一 00:00（展示窗口起点，永远跟随当前时间，不受任何配置锚定）
     */
    private static function weekStart(?Carbon $now): Carbon
    {
        return ($now ? $now->copy() : self::now())->startOfWeek(Carbon::MONDAY)->startOfDay();
    }
}
