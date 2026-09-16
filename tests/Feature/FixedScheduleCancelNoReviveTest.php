<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 取消约课：从约课表里消失，且不会被固定场滚动补齐"复活"
 *
 * 背景：固定场只展开"本周 + 下周"，每次请求会滚动补齐（EnsureFixedScheduleWindow）。
 * materialize() 的幂等键是 `fixed_schedule_id|开始时间`，把记录物理删掉后这个键就消失了，
 * 下一次补齐会按模板把课重新排回来 —— 也就是用户看到的"取消后课表里又出现了"。
 *
 * 覆盖：
 * - 固定场来源的课：取消后留一条 cancelled 占位（约课表不展示，但让补齐幂等跳过）
 * - 再次 ensureWindow 不重新生成 → 不复活
 * - 手工约课（非固定场来源）仍是物理删除
 * - 已经取消过的课再次定位时给出明确提示
 */
class FixedScheduleCancelNoReviveTest extends TestCase
{
    use RefreshDatabase;

    private FixedScheduleService $fixed;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // 固定在周三 10:00：本周三 20:00 与下周三 20:00 都还没开始
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

        $this->fixed = app(FixedScheduleService::class);
        $this->booking = app(BookingService::class);
    }

    private function makeTemplate(array $attrs = []): FixedSchedule
    {
        return FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1A',
            'weekday' => 3,
            'start_time' => '20:00',
            'end_time' => '21:00',
            'student_name' => '小明',
            'coach_name' => '张',
            'exclusive' => true,
            'is_student' => true,
            'active' => true,
            ...$attrs,
        ]);
    }

    private function allRecords()
    {
        return BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
    }

    /** 约课表（Excel / 列表）里出现的记录 id */
    private function weeklyIds(): array
    {
        return $this->booking->weekly()
            ->flatMap(fn (array $week) => $week['items']->pluck('id'))
            ->all();
    }

    /** 固定场来源的课：取消后不展示，且补齐不会把它排回来 */
    public function test_cancel_fixed_booking_is_not_revived_by_ensure_window(): void
    {
        $this->makeTemplate();
        $this->fixed->ensureWindow('tennis_a');
        $this->assertSame(2, $this->allRecords()->count());

        $target = $this->allRecords()
            ->first(fn (BookingRecord $b) => $b->start_at->format('Y-m-d H:i') === '2026-09-23 20:00');

        $result = $this->booking->delete($target->id);

        $this->assertTrue($result['success']);
        // 行保留为「已取消」占位，而不是被物理删除
        $this->assertSame(BookingRecord::STATUS_CANCELLED, $target->fresh()->status);

        // 约课表里不再出现这节课
        $this->assertNotContains($target->id, $this->weeklyIds());
        $this->assertCount(1, $this->weeklyIds());

        // 再跑一次滚动补齐：幂等键命中占位行 → 不复活
        $again = $this->fixed->ensureWindow('tennis_a');

        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['skipped']);
        $this->assertSame(2, $this->allRecords()->count());
        $this->assertSame(1, $this->allRecords()->where('status', BookingRecord::STATUS_BOOKED)->count());
    }

    /** 手工约课（非固定场来源）没有模板托底，仍然按原方式物理删除 */
    public function test_manual_booking_is_physically_deleted(): void
    {
        $manual = BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '小红',
            'coach_name' => '张',
            'start_at' => Carbon::parse('2026-09-17 19:00', 'Asia/Shanghai'),
            'end_at' => Carbon::parse('2026-09-17 20:00', 'Asia/Shanghai'),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
        ]);

        $result = $this->booking->delete($manual->id);

        $this->assertTrue($result['success']);
        $this->assertNull(
            BookingRecord::withoutGlobalScope(OrganizationScope::class)->find($manual->id)
        );
    }

    /** 已取消的课再次定位时明确提示，避免用户以为没取消成功而反复操作 */
    public function test_already_cancelled_lesson_reports_status(): void
    {
        $this->makeTemplate();
        $this->fixed->ensureWindow('tennis_a');

        $target = $this->allRecords()
            ->first(fn (BookingRecord $b) => $b->start_at->format('Y-m-d H:i') === '2026-09-23 20:00');

        $this->booking->delete($target->id);

        $located = $this->booking->locateTarget([
            'student_name' => '小明',
            'start_at' => '2026-09-23 20:00',
        ]);

        $this->assertFalse($located['success']);
        $this->assertStringContainsString('已经取消过', $located['message']);
    }
}
