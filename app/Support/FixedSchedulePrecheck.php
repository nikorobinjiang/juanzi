<?php

namespace App\Support;

use App\Models\FixedSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 未来固定课表模板预检
 *
 * 固定场现在只提前展开两周（本周 + 下周），第 3~4 周在 booking_records 里还没有记录。
 * 如果临时约课只看库内记录，等页面自动补齐时该固定场会被判"场地冲突"而跳过，
 * 固定场就静默丢失了。这里直接按模板判定，让临时约课提前避让未来的固定场。
 *
 * 场地判定与 BookingService::checkConflict 保持一致：调用方传入 venueSlots()（含所属整场，
 * 因此整场「1」与半场「1A/1B」互斥，而 1A 与 1B 可以拼场）。
 *
 * 不依赖任何 Service，避免与 BookingService / FixedScheduleService 形成循环依赖。
 */
class FixedSchedulePrecheck
{
    /**
     * 场地预检：未来的固定场模板占用了同一片场地
     *
     * @param  array<int, string>  $venueSlots  场地占位集合（含所属整场）
     */
    public function conflictForVenue(array $venueSlots, Carbon $startAt, Carbon $endAt): ?FixedSchedule
    {
        if ($venueSlots === []) {
            return null;
        }

        return $this->candidates($startAt)->first(
            fn (FixedSchedule $tpl) => in_array(trim((string) $tpl->venue), $venueSlots, true)
                && $this->timeOverlaps($tpl, $startAt, $endAt)
        );
    }

    /**
     * 教练预检：未来的固定场模板里同一位教练已有课（同一教练同一时间只能带一节课）
     */
    public function conflictForCoach(string $coach, Carbon $startAt, Carbon $endAt): ?FixedSchedule
    {
        $coach = trim($coach);

        if ($coach === '') {
            return null;
        }

        return $this->candidates($startAt)->first(
            fn (FixedSchedule $tpl) => trim((string) $tpl->coach_name) !== ''
                && mb_stripos((string) $tpl->coach_name, $coach) !== false
                && $this->timeOverlaps($tpl, $startAt, $endAt)
        );
    }

    /**
     * 同一天（星期）的启用模板，且落在生效期内
     *
     * @return Collection<int, FixedSchedule>
     */
    private function candidates(Carbon $startAt): Collection
    {
        return FixedSchedule::query()
            ->where('active', true)
            ->where('weekday', (int) $startAt->dayOfWeekIso)
            ->get()
            ->filter(fn (FixedSchedule $tpl) => $this->inEffectiveRange($tpl, $startAt))
            ->values();
    }

    /** 生效期判断：effective_from / effective_to 为空表示不限 */
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
     * 模板当天的时段与给定时段是否重叠
     *
     * 与 FixedScheduleService::expandTemplate() 一致：跨零点（23:00-00:30）时结束时间落到次日。
     */
    private function timeOverlaps(FixedSchedule $tpl, Carbon $startAt, Carbon $endAt): bool
    {
        if ((string) $tpl->start_time === '' || (string) $tpl->end_time === '') {
            return false;
        }

        $day = $startAt->copy()->startOfDay();
        [$sh, $sm] = $this->splitTime((string) $tpl->start_time);
        [$eh, $em] = $this->splitTime((string) $tpl->end_time);

        $tplStart = $day->copy()->setTime((int) $sh, (int) $sm);
        $tplEnd = $day->copy()->setTime((int) $eh, (int) $em);

        if ($tplEnd->lte($tplStart)) {
            $tplEnd->addDay();
        }

        return $tplStart->lt($endAt) && $tplEnd->gt($startAt);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function splitTime(string $time): array
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return [(int) $h, (int) $m];
    }
}
