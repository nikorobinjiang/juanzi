<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Support\BookingWindow;
use App\Support\VenueSlots;
use Carbon\Carbon;
use Closure;

/**
 * 场地空闲查询（唯一实现）
 *
 * 可约单元：开发区 1 号 / 2 号场地各有两种用法
 * - 整场 1、整场 2：「不拼」，整场归该场次使用，不能再拆
 * - 1A/1B、2A/2B：拼场半场，同一时段两个半场可以给两批人
 *
 * 互斥规则由 VenueSlots 提供（与约课冲突检测同源）：
 * - 占用整场 1 → 1A、1B 都不可约
 * - 占用 1A → 整场 1 不可约，但 1B 仍可拼
 * - 派生：整场 1 可约 ⟺ 1A 空闲 且 1B 空闲
 *
 * 空闲算法：把占用区间按"天"裁剪、排序、合并后，与营业时段（默认 07:00-23:00）求补集，
 * 得到真实的连续空闲段（支持 17:30-18:30 这类非整点场次）。
 *
 * 占用来源：
 * - booking_records（已取消的不算）
 * - fixed_schedules 模板：按星期与生效期展开，覆盖 booking_records 还没展开到的第 3~4 周
 */
class VenueAvailabilityService
{
    /**
     * 开发区可约单元（数组顺序即展示顺序）
     *
     * @return array<int, string>
     */
    public function developmentUnits(): array
    {
        $units = (array) config('doubao.booking.availability.development_units', ['1', '1A', '1B', '2', '2A', '2B']);

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $unit): string => trim((string) $unit),
            $units
        ))));
    }

    /**
     * 多个可约单元的空闲时段
     *
     * @param  array<int, string>  $units  可约单元（如 ['1', '1A', '1B', '2', '2A', '2B']）
     * @return array<string, array<int, array{date: string, slots: array<int, string>}>>
     *                                     单元 => 每天空闲段；slots 形如 ['08:00-10:00', '11:00-18:00']
     */
    public function slotsFor(array $units, Carbon $from, Carbon $to): array
    {
        $units = array_values(array_unique(array_filter(array_map(
            fn (mixed $unit): string => trim((string) $unit),
            $units
        ))));

        if ($units === [] || $to->lt($from)) {
            return [];
        }

        // 整个日期范围只查两次库（记录 + 模板），不按单元 / 按天循环查
        $occupancy = $this->collectOccupancy($units, $from, $to);

        $result = [];

        foreach ($units as $unit) {
            $days = [];

            for ($day = $from->copy()->startOfDay(); $day->lte($to->copy()->startOfDay()); $day->addDay()) {
                $days[] = [
                    'date' => $day->format('Y-m-d'),
                    'slots' => $this->freeIntervals($occupancy[$unit] ?? [], $day),
                ];
            }

            $result[$unit] = $days;
        }

        return $result;
    }

    /**
     * 单个场地的空闲时段（开发区整场/半场、以及龙安湖等其它区域场地都适用）
     *
     * @return array<int, array{date: string, slots: array<int, string>}>
     */
    public function slotsForVenue(string $venue, Carbon $from, Carbon $to): array
    {
        $venue = trim($venue);

        if ($venue === '') {
            return [];
        }

        return $this->slotsFor([$venue], $from, $to)[$venue] ?? [];
    }

    /* -----------------------------------------------------------------
     | 内部：占用收集
     | ----------------------------------------------------------------- */

    /**
     * 收集"单元 => 占用区间"
     *
     * @param  array<int, string>  $units
     * @return array<string, array<int, array{0: Carbon, 1: Carbon}>>
     */
    private function collectOccupancy(array $units, Carbon $from, Carbon $to): array
    {
        $occupancy = array_fill_keys($units, []);

        $register = function (string $venue, Carbon $start, Carbon $end) use (&$occupancy): void {
            // 与约课冲突检测同源：整场占用连带两个半场，半场占用连带整场
            foreach (VenueSlots::of($venue) as $unit) {
                if (array_key_exists($unit, $occupancy)) {
                    $occupancy[$unit][] = [$start->copy(), $end->copy()];
                }
            }
        };

        $this->registerBookings($register, $from, $to);
        $this->registerTemplates($register, $from, $to);

        return $occupancy;
    }

    /**
     * @param  Closure(string, Carbon, Carbon): void  $register
     */
    private function registerBookings(Closure $register, Carbon $from, Carbon $to): void
    {
        $rangeStart = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->endOfDay();

        BookingRecord::query()
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->get(['venue', 'start_at', 'end_at'])
            ->each(function (BookingRecord $booking) use ($register): void {
                if ($booking->start_at === null || $booking->end_at === null) {
                    return;
                }

                $register((string) $booking->venue, $booking->start_at, $booking->end_at);
            });
    }

    /**
     * 固定课表模板占用：按星期 + 生效期展开，覆盖 booking_records 未展开的周
     *
     * @param  Closure(string, Carbon, Carbon): void  $register
     */
    private function registerTemplates(Closure $register, Carbon $from, Carbon $to): void
    {
        $templates = FixedSchedule::query()->where('active', true)->get();

        if ($templates->isEmpty()) {
            return;
        }

        $lastDay = $to->copy()->startOfDay();

        for ($day = $from->copy()->startOfDay(); $day->lte($lastDay); $day->addDay()) {
            foreach ($templates as $tpl) {
                if ((int) $tpl->weekday !== $day->dayOfWeekIso || ! $this->inEffectiveRange($tpl, $day)) {
                    continue;
                }

                [$start, $end] = $this->templateInterval($tpl, $day);

                if ($start !== null && $end !== null) {
                    $register((string) $tpl->venue, $start, $end);
                }
            }
        }
    }

    /** 模板在该日是否处于生效期内 */
    private function inEffectiveRange(FixedSchedule $tpl, Carbon $day): bool
    {
        if ($tpl->effective_from && $day->copy()->startOfDay()->lt($tpl->effective_from->copy()->startOfDay())) {
            return false;
        }

        if ($tpl->effective_to && $day->copy()->startOfDay()->gt($tpl->effective_to->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * 模板在该日的时间区间（跨零点如 23:00-00:30 时结束时间落到次日）
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function templateInterval(FixedSchedule $tpl, Carbon $day): array
    {
        $startTime = trim((string) $tpl->start_time);
        $endTime = trim((string) $tpl->end_time);

        if ($startTime === '' || $endTime === '') {
            return [null, null];
        }

        [$sh, $sm] = array_pad(explode(':', $startTime), 2, '0');
        [$eh, $em] = array_pad(explode(':', $endTime), 2, '0');

        if (! is_numeric($sh) || ! is_numeric($eh)) {
            return [null, null];
        }

        $start = $day->copy()->setTime((int) $sh, (int) $sm);
        $end = $day->copy()->setTime((int) $eh, (int) $em);

        if ($end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /* -----------------------------------------------------------------
     | 内部：区间补集
     | ----------------------------------------------------------------- */

    /**
     * 当天空闲时段：营业时段内、去掉已占用区间后的连续段
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $busy
     * @return array<int, string>
     */
    private function freeIntervals(array $busy, Carbon $day): array
    {
        $openStart = $day->copy()->setTime((int) config('doubao.booking.hours.start', 7), 0);
        $openEnd = $day->copy()->setTime((int) config('doubao.booking.hours.end', 23), 0);
        $minMinutes = max(0, (int) config('doubao.booking.availability.min_free_minutes', 30));

        // 查"今天"时，已经过去的时段不再算空闲
        if ($day->isSameDay(BookingWindow::now())) {
            $now = BookingWindow::now();

            if ($now->gt($openStart)) {
                $openStart = $now->copy();
            }
        }

        if ($openEnd->lte($openStart)) {
            return [];
        }

        $free = [];
        $cursor = $openStart->copy();

        foreach ($this->mergeBusy($busy, $openStart, $openEnd) as [$start, $end]) {
            if ($start->gt($cursor)) {
                $free[] = [$cursor->copy(), $start->copy()];
            }

            if ($end->gt($cursor)) {
                $cursor = $end->copy();
            }
        }

        if ($cursor->lt($openEnd)) {
            $free[] = [$cursor->copy(), $openEnd->copy()];
        }

        // 按开始 → 结束顺序求时长（Carbon 3 的 diffInMinutes 是带符号的）
        return array_values(array_filter(array_map(
            fn (array $range): ?string => $range[0]->diffInMinutes($range[1]) >= $minMinutes
                ? $range[0]->format('H:i').'-'.$range[1]->format('H:i')
                : null,
            $free
        )));
    }

    /**
     * 占用区间裁剪到当天营业时段内并合并重叠
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $busy
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function mergeBusy(array $busy, Carbon $openStart, Carbon $openEnd): array
    {
        $clipped = [];

        foreach ($busy as [$start, $end]) {
            $from = $start->gt($openStart) ? $start->copy() : $openStart->copy();
            $to = $end->lt($openEnd) ? $end->copy() : $openEnd->copy();

            if ($from->lt($to)) {
                $clipped[] = [$from, $to];
            }
        }

        usort($clipped, fn (array $a, array $b): int => $a[0]->lt($b[0]) ? -1 : ($a[0]->gt($b[0]) ? 1 : 0));

        $merged = [];

        foreach ($clipped as $interval) {
            $last = array_key_last($merged);

            if ($last !== null && $interval[0]->lte($merged[$last][1])) {
                if ($interval[1]->gt($merged[$last][1])) {
                    $merged[$last][1] = $interval[1];
                }

                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }
}
