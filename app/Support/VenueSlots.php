<?php

namespace App\Support;

/**
 * 场地占位集合：整场 / 半场互斥关系的单一事实来源
 *
 * 开发区只有 1 号、2 号两个场地，每个都能拼场：
 * - 1 → 1A + 1B，2 → 2A + 2B
 * - 整场（1/2）被占用 = "不拼"：两个半场都不可约
 * - 半场（1A/1B）被占用：整场不可约，另一个半场仍可拼
 *
 * 约课冲突检测（BookingService::checkConflict）与场地空闲查询
 * （VenueAvailabilityService）共用这里的实现，保证两边口径一致。
 */
final class VenueSlots
{
    /**
     * 某个场地被占用时，会连带占住哪些场地
     *
     * - 1  → ['1', '1A', '1B']（约整场时，1A/1B 都不能再约）
     * - 1A → ['1A', '1']       （1 号整场被占时，1A 也不可约）
     * - 其它区域场地 → 仅自身
     *
     * @return array<int, string>
     */
    public static function of(string $venue): array
    {
        $venue = trim($venue);

        if ($venue === '') {
            return [];
        }

        foreach (self::fullCourts() as $full => $halves) {
            if ((string) $full === $venue) {
                $slots = [$venue];

                foreach ((array) $halves as $half) {
                    $slots[] = (string) $half;
                }

                return array_values(array_unique($slots));
            }
        }

        foreach (self::fullCourts() as $full => $halves) {
            foreach ((array) $halves as $half) {
                if ((string) $half === $venue) {
                    return array_values(array_unique([$venue, (string) $full]));
                }
            }
        }

        return [$venue];
    }

    /**
     * 整场 → 半场映射：['1' => ['1A', '1B'], '2' => ['2A', '2B']]
     *
     * @return array<array-key, mixed>
     */
    public static function fullCourts(): array
    {
        return (array) config('doubao.booking.full_courts', []);
    }
}
