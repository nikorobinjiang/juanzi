<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingQueryTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = app(BookingService::class);
    }

    /**
     * 造一条约课记录（默认：小明 / 王教练 / 今天 10:00 / 1A / 已约）
     */
    private function makeBooking(array $attrs = []): BookingRecord
    {
        $startAt = $attrs['start_at'] ?? Carbon::today()->setTime(10, 0);

        return BookingRecord::create([
            'student_name' => '小明',
            'coach_name' => '王教练',
            'start_at' => $startAt,
            'end_at' => $attrs['end_at'] ?? $startAt->copy()->addHour(),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
            ...$attrs,
        ]);
    }

    /** 统计口径：只统计已完成课程，已预约/已取消不算，按学员/教练过滤 */
    public function test_count_completed_lessons_only_counts_completed(): void
    {
        $this->makeBooking(['status' => BookingRecord::STATUS_COMPLETED, 'start_at' => now()->subDays(3)->setTime(10, 0)]);
        $this->makeBooking(['status' => BookingRecord::STATUS_COMPLETED, 'start_at' => now()->subDays(2)->setTime(10, 0)]);
        $this->makeBooking(['start_at' => now()->addDays(1)->setTime(10, 0)]);
        $this->makeBooking(['status' => BookingRecord::STATUS_CANCELLED, 'start_at' => now()->subDays(1)->setTime(10, 0)]);
        $this->makeBooking([
            'student_name' => '小红',
            'coach_name' => '李教练',
            'status' => BookingRecord::STATUS_COMPLETED,
            'start_at' => now()->subDays(4)->setTime(10, 0),
        ]);

        $this->assertSame(2, $this->booking->countCompletedLessons('小明'));
        $this->assertSame(2, $this->booking->countCompletedLessons('', '王教练'));
        $this->assertSame(1, $this->booking->countCompletedLessons('', '李教练'));
    }

    /** 最近一次已完成课程 / 下一次未开始的已约课程 */
    public function test_last_and_next_lesson(): void
    {
        $this->makeBooking(['status' => BookingRecord::STATUS_COMPLETED, 'start_at' => now()->subDays(3)->setTime(10, 0)]);
        $this->makeBooking(['status' => BookingRecord::STATUS_COMPLETED, 'start_at' => now()->subDays(2)->setTime(10, 0)]);
        $this->makeBooking(['start_at' => now()->addDays(1)->setTime(10, 0)]);
        $this->makeBooking(['start_at' => now()->addDays(3)->setTime(10, 0)]);

        $last = $this->booking->lastLesson('小明');
        $next = $this->booking->nextLesson('小明');

        $this->assertNotNull($last);
        $this->assertSame(now()->subDays(2)->format('Y-m-d H:i'), $last->start_at->format('Y-m-d H:i'));
        $this->assertNotNull($next);
        $this->assertSame(now()->addDays(1)->format('Y-m-d H:i'), $next->start_at->format('Y-m-d H:i'));
    }

    /** 教练空闲时段：被占用时段不出现，其余时段保留 */
    public function test_coach_availability_excludes_occupied_slots(): void
    {
        $this->makeBooking(['start_at' => Carbon::today()->setTime(10, 0)]);

        $days = $this->booking->coachAvailability('王教练', Carbon::today(), Carbon::today());

        $this->assertCount(1, $days);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $days[0]['date']);
        $this->assertContains('08:00-09:00', $days[0]['slots']);
        $this->assertNotContains('10:00-11:00', $days[0]['slots']);
    }

    /** 场地空闲时段：按场地独立计算，互不影响 */
    public function test_venue_availability_excludes_occupied_slots(): void
    {
        $this->makeBooking(['venue' => '1B', 'start_at' => Carbon::today()->setTime(14, 0)]);

        $days = $this->booking->venueAvailability('1B', Carbon::today(), Carbon::today());

        $this->assertNotContains('14:00-15:00', $days[0]['slots']);
        $this->assertContains('13:00-14:00', $days[0]['slots']);
        $this->assertContains('15:00-16:00', $days[0]['slots']);

        // 其他场地不受影响
        $other = $this->booking->venueAvailability('1A', Carbon::today(), Carbon::today());
        $this->assertContains('14:00-15:00', $other[0]['slots']);
    }

    /** 教练冲突检测：重叠判冲突、紧邻不冲突、可忽略自身 */
    public function test_check_coach_conflict_detects_overlap(): void
    {
        $this->makeBooking(['start_at' => Carbon::today()->setTime(10, 0)]);

        // 10:30-11:30 与 10:00-11:00 重叠
        $this->assertNotNull($this->booking->checkCoachConflict(
            '王教练', Carbon::today()->setTime(10, 30), Carbon::today()->setTime(11, 30)
        ));
        // 9:30-10:30 与 10:00-11:00 重叠
        $this->assertNotNull($this->booking->checkCoachConflict(
            '王教练', Carbon::today()->setTime(9, 30), Carbon::today()->setTime(10, 30)
        ));
        // 11:00 紧邻不冲突
        $this->assertNull($this->booking->checkCoachConflict(
            '王教练', Carbon::today()->setTime(11, 0), Carbon::today()->setTime(12, 0)
        ));
        // 忽略自身
        $booking = $this->booking->all()->first();
        $this->assertNotNull($booking);
        $this->assertNull($this->booking->checkCoachConflict(
            '王教练', Carbon::today()->setTime(10, 30), Carbon::today()->setTime(11, 30), $booking->id
        ));
    }

    /** 约课：同一教练同一时间只能带一节课，换场地也不能绕过 */
    public function test_create_blocks_coach_conflict(): void
    {
        $this->makeBooking(['start_at' => Carbon::today()->setTime(10, 0)]);

        $result = $this->booking->create([
            'student_name' => '小红',
            'coach_name' => '王教练',
            'start_at' => Carbon::today()->setTime(10, 0)->format('Y-m-d H:i'),
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('教练冲突', $result['message']);

        // 换场地同样被拦截
        $result2 = $this->booking->create([
            'student_name' => '小红',
            'coach_name' => '王教练',
            'start_at' => Carbon::today()->setTime(10, 0)->format('Y-m-d H:i'),
            'venue' => '2B',
        ]);
        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('教练冲突', $result2['message']);
    }

    /** 约课：原有场地冲突校验仍然生效（不同教练同一场地同一时间） */
    public function test_create_still_blocks_venue_conflict(): void
    {
        $this->makeBooking(['student_name' => '小红', 'start_at' => Carbon::today()->setTime(10, 0)]);

        $result = $this->booking->create([
            'student_name' => '小刚',
            'coach_name' => '李教练',
            'start_at' => Carbon::today()->setTime(10, 0)->format('Y-m-d H:i'),
            'venue' => '1A',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('场地冲突', $result['message']);
    }

    /** 约课：自动分配时四个场地都被占用则失败 */
    public function test_create_auto_assign_fails_when_all_venues_busy(): void
    {
        foreach (['1A', '1B', '2A', '2B'] as $venue) {
            $this->makeBooking(['venue' => $venue, 'student_name' => '学员'.$venue, 'start_at' => Carbon::today()->setTime(10, 0)]);
        }

        $result = $this->booking->create([
            'student_name' => '小刚',
            'coach_name' => '李教练',
            'start_at' => Carbon::today()->setTime(10, 0)->format('Y-m-d H:i'),
        ]);

        $this->assertFalse($result['success']);
    }

    /** 改期：撞上教练已有课程被拦截；只换教练到空闲教练则成功 */
    public function test_update_blocks_coach_conflict(): void
    {
        $this->makeBooking(['student_name' => '小明', 'start_at' => Carbon::today()->setTime(10, 0)]);
        $target = $this->makeBooking(['student_name' => '小红', 'start_at' => Carbon::tomorrow()->setTime(10, 0)]);

        // 把小红明天的课改到今天 10:00（王教练此时带小明）
        $result = $this->booking->update($target->id, [
            'start_at' => Carbon::today()->setTime(10, 0)->format('Y-m-d H:i'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('教练冲突', $result['message']);

        // 只换教练到同一时间空闲的李教练 → 成功
        $result2 = $this->booking->update($target->id, ['coach_name' => '李教练']);
        $this->assertTrue($result2['success']);
        $this->assertSame('李教练', $target->refresh()->coach_name);
    }
}
