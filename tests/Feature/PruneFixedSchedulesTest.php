<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 固定场存量清理：删除窗口之外、尚未开始的固定场记录
 *
 * 固定场只保留"本周 + 下周"（本测试：2026-09-14 ~ 09-28），
 * 历史 / 已完成 / 已取消 / 手工约课记录都不清理。
 */
class PruneFixedSchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'Asia/Shanghai'));

        config([
            'doubao.fixed_schedule.weeks' => 2,
            'doubao.window.weeks' => 4,
            'doubao.fixed_schedule.start' => '',
        ]);

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));
    }

    private function makeRecord(string $student, string $datetime, array $attrs = []): BookingRecord
    {
        $startAt = Carbon::parse($datetime, 'Asia/Shanghai');

        return BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => $student,
            'coach_name' => '张',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHour(),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
            ...$attrs,
        ]);
    }

    /** 只删"窗口之外 + 未开始 + 固定场来源 + 已约状态"的记录 */
    public function test_prune_removes_only_future_fixed_records_beyond_window(): void
    {
        // 窗口内（下周三）→ 保留
        $this->makeRecord('窗口内', '2026-09-23 10:00', ['fixed_schedule_id' => 1]);
        // 窗口外未开始 → 删除
        $deleted = $this->makeRecord('窗口外固定场', '2026-09-30 10:00', ['fixed_schedule_id' => 1]);
        // 历史（已完成）→ 保留
        $this->makeRecord('历史已完成', '2026-09-09 10:00', [
            'fixed_schedule_id' => 1,
            'status' => BookingRecord::STATUS_COMPLETED,
        ]);
        // 窗口外但已取消 → 保留
        $this->makeRecord('窗口外已取消', '2026-09-30 12:00', [
            'fixed_schedule_id' => 1,
            'status' => BookingRecord::STATUS_CANCELLED,
        ]);
        // 窗口外的手工约课 → 保留（只做展示过滤，不删数据）
        $this->makeRecord('窗口外手工', '2026-10-01 10:00');

        $this->artisan('fixed-schedules:prune', ['--org' => 'tennis_a'])->assertSuccessful();

        $this->assertNull(BookingRecord::withoutGlobalScope(\App\Models\Scopes\OrganizationScope::class)->find($deleted->id));
        $this->assertSame(4, BookingRecord::withoutGlobalScope(\App\Models\Scopes\OrganizationScope::class)->count());
    }

    /** --dry-run 只统计不删除 */
    public function test_prune_dry_run_does_not_delete(): void
    {
        $this->makeRecord('窗口外固定场', '2026-09-30 10:00', ['fixed_schedule_id' => 1]);

        $this->artisan('fixed-schedules:prune', ['--org' => 'tennis_a', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, BookingRecord::withoutGlobalScope(\App\Models\Scopes\OrganizationScope::class)->count());
    }

    /** 可重复执行：第二次没有可清理数据，命令仍成功 */
    public function test_prune_is_repeatable(): void
    {
        $this->makeRecord('窗口外固定场', '2026-09-30 10:00', ['fixed_schedule_id' => 1]);

        $this->artisan('fixed-schedules:prune', ['--org' => 'tennis_a'])->assertSuccessful();
        $this->artisan('fixed-schedules:prune', ['--org' => 'tennis_a'])->assertSuccessful();

        $this->assertSame(0, BookingRecord::withoutGlobalScope(\App\Models\Scopes\OrganizationScope::class)->count());
    }
}
