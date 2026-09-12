<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 未来固定课表模板预检
 *
 * 固定场只提前展开两周，第 3~4 周在库内还没有记录。
 * 若此时约课不做模板预检，等页面自动补齐时该固定场会被判"场地冲突"而丢失。
 */
class FixedSchedulePrecheckTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

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

        $this->booking = app(BookingService::class);
    }

    private function makeTemplate(array $attrs = []): FixedSchedule
    {
        return FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1A',
            'weekday' => 3,
            'start_time' => '14:00',
            'end_time' => '15:00',
            'student_name' => '小明',
            'coach_name' => '张',
            'exclusive' => true,
            'is_student' => true,
            'active' => true,
            ...$attrs,
        ]);
    }

    /** 窗口外的固定场（下下周）同样能挡住临时约课 */
    public function test_venue_precheck_blocks_booking_beyond_materialized_window(): void
    {
        $this->makeTemplate();

        // 下下周三 14:00-15:00 同一片场地 → 冲突（库内此时没有记录，命中的是模板）
        $conflict = $this->booking->checkConflict(
            '1A',
            Carbon::parse('2026-09-30 14:00', 'Asia/Shanghai'),
            Carbon::parse('2026-09-30 15:00', 'Asia/Shanghai')
        );

        $this->assertInstanceOf(FixedSchedule::class, $conflict);

        // 时间不重叠 → 无冲突
        $this->assertNull($this->booking->checkConflict(
            '1A',
            Carbon::parse('2026-09-30 15:00', 'Asia/Shanghai'),
            Carbon::parse('2026-09-30 16:00', 'Asia/Shanghai')
        ));

        // 不同星期 → 无冲突
        $this->assertNull($this->booking->checkConflict(
            '1A',
            Carbon::parse('2026-10-01 14:00', 'Asia/Shanghai'),
            Carbon::parse('2026-10-01 15:00', 'Asia/Shanghai')
        ));
    }

    /** 整场（1）与半场（1A/1B）互斥，半场之间可以拼场 */
    public function test_whole_court_template_conflicts_with_half_court_booking(): void
    {
        $this->makeTemplate(['venue' => '1', 'weekday' => 4]);

        $time = ['2026-10-01 14:00', '2026-10-01 15:00'];

        $this->assertNotNull($this->booking->checkConflict(
            '1B',
            Carbon::parse($time[0], 'Asia/Shanghai'),
            Carbon::parse($time[1], 'Asia/Shanghai')
        ));

        // 另一个整场的半场不受影响
        $this->assertNull($this->booking->checkConflict(
            '2A',
            Carbon::parse($time[0], 'Asia/Shanghai'),
            Carbon::parse($time[1], 'Asia/Shanghai')
        ));
    }

    /** 同一教练同一时间只能带一节课，与场地无关 */
    public function test_coach_precheck_blocks_same_coach_other_venue(): void
    {
        $this->makeTemplate(['venue' => '2A']);

        $startAt = Carbon::parse('2026-09-30 14:00', 'Asia/Shanghai');
        $endAt = Carbon::parse('2026-09-30 15:00', 'Asia/Shanghai');

        $this->assertInstanceOf(FixedSchedule::class, $this->booking->checkCoachConflict('张', $startAt, $endAt));
        $this->assertNull($this->booking->checkCoachConflict('李', $startAt, $endAt));
    }

    /** 库内已有的记录优先（返回记录本身），模板预检只作为窗口外的兜底 */
    public function test_materialized_record_takes_precedence_over_template(): void
    {
        $this->makeTemplate();

        $startAt = Carbon::parse('2026-09-30 14:00', 'Asia/Shanghai');
        $record = BookingRecord::create([
            'student_name' => '小李',
            'coach_name' => '王教练',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHour(),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
        ]);

        $conflict = $this->booking->checkConflict('1A', $startAt, $startAt->copy()->addHour());

        $this->assertInstanceOf(BookingRecord::class, $conflict);
        $this->assertSame($record->id, $conflict->id);
    }
}
