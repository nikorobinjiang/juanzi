<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\User;
use App\Services\VenueAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 场地空闲查询
 *
 * 开发区只有 1 号、2 号两个场地，每个都能拼场：
 * - 整场 1/2 = "不拼"：整场被占用时两个半场都不可约
 * - 1A/1B、2A/2B = 可拼半场：任一半场被占用，另一半场仍可约，但整场不可约
 * - 派生：整场 1 可约 ⟺ 1A 空闲 且 1B 空闲
 *
 * 空闲时段按"已占用区间求补集"计算，支持 17:30-18:30 这类非整点场次。
 */
class VenueAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private VenueAvailabilityService $venues;

    /** 未来某天：不受"今天已过时段"裁剪影响 */
    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        // 固定"现在"为 2026-09-16（周三）10:00，保证"今天"的断言稳定
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'Asia/Shanghai'));

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));

        $this->venues = app(VenueAvailabilityService::class);
        $this->day = Carbon::now('Asia/Shanghai')->addDays(2)->startOfDay();
    }

    /** 造一条约课记录 */
    private function book(string $venue, string $start, string $end, ?Carbon $day = null): BookingRecord
    {
        $day = ($day ?? $this->day)->copy();

        return BookingRecord::create([
            'student_name' => '小明',
            'coach_name' => '王教练',
            'start_at' => $day->copy()->setTimeFromTimeString($start),
            'end_at' => $day->copy()->setTimeFromTimeString($end),
            'venue' => $venue,
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function slots(string $unit, ?Carbon $day = null): array
    {
        $day = $day ?? $this->day;

        return $this->venues->slotsForVenue($unit, $day, $day)[0]['slots'] ?? [];
    }

    /** 拼场：1A 被约，1B 仍可约，但整场 1 不可约 */
    public function test_half_court_booking_keeps_the_other_half_bookable(): void
    {
        $this->book('1A', '10:00', '11:00');

        $this->assertSame(['07:00-10:00', '11:00-23:00'], $this->slots('1A'));
        $this->assertSame(['07:00-23:00'], $this->slots('1B'));
        $this->assertSame(['07:00-10:00', '11:00-23:00'], $this->slots('1'));
    }

    /** 整场（不拼）只有在两个半场都空的时候才可约 */
    public function test_full_court_is_free_only_when_both_halves_are_free(): void
    {
        $this->book('1A', '10:00', '11:00');
        $this->book('1B', '12:00', '13:00');

        $this->assertSame(['07:00-10:00', '11:00-12:00', '13:00-23:00'], $this->slots('1'));
        $this->assertSame(['07:00-10:00', '11:00-23:00'], $this->slots('1A'));
        $this->assertSame(['07:00-12:00', '13:00-23:00'], $this->slots('1B'));
    }

    /** 约了整场（不拼），两个半场都不能再约 */
    public function test_whole_court_booking_blocks_both_halves(): void
    {
        $this->book('2', '14:00', '16:00');

        $this->assertSame(['07:00-14:00', '16:00-23:00'], $this->slots('2A'));
        $this->assertSame(['07:00-14:00', '16:00-23:00'], $this->slots('2B'));
        $this->assertSame(['07:00-14:00', '16:00-23:00'], $this->slots('2'));
    }

    /** 非整点场次：只占真实占用区间，零星空档仍然可见 */
    public function test_non_hourly_booking_keeps_partial_gaps(): void
    {
        $this->book('2A', '17:30', '18:30');

        $this->assertSame(['07:00-17:30', '18:30-23:00'], $this->slots('2A'));
        $this->assertSame(['07:00-23:00'], $this->slots('2B'));
        $this->assertSame(['07:00-17:30', '18:30-23:00'], $this->slots('2'));
    }

    /** 库里还没展开的固定课表模板，也算作占用 */
    public function test_fixed_schedule_template_counts_as_occupied(): void
    {
        FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1A',
            'weekday' => (int) $this->day->dayOfWeekIso,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'student_name' => '小明',
            'coach_name' => '王',
            'exclusive' => false,
            'is_student' => true,
            'active' => true,
        ]);

        $this->assertSame(0, BookingRecord::count(), '模板不应在库里展开出记录');
        $this->assertSame(['07:00-09:00', '11:00-23:00'], $this->slots('1A'));
        $this->assertSame(['07:00-23:00'], $this->slots('1B'));
    }

    /** 小于 30 分钟的碎片空档不展示 */
    public function test_fragments_shorter_than_threshold_are_hidden(): void
    {
        $this->book('1A', '09:00', '10:00');
        $this->book('1A', '10:20', '11:00');

        // 10:00-10:20 只有 20 分钟，约不了一节课，不展示
        $this->assertSame(['07:00-09:00', '11:00-23:00'], $this->slots('1A'));
    }

    /** 查"今天"时，已经过去的时段不再算空闲 */
    public function test_past_time_is_excluded_when_querying_today(): void
    {
        $today = Carbon::now('Asia/Shanghai');

        // 现在固定为 10:00，营业时段 07:00-23:00
        $this->assertSame(['10:00-23:00'], $this->slots('1A', $today));
    }

    /** 默认只查开发区 1/2 号场地的六个可约单元 */
    public function test_development_units_cover_both_courts(): void
    {
        $units = $this->venues->developmentUnits();

        $this->assertSame(['1', '1A', '1B', '2', '2A', '2B'], $units);
        $this->assertSame(
            $units,
            array_map('strval', array_keys($this->venues->slotsFor($units, $this->day, $this->day)))
        );
    }

    /** 其它区域场地：用户点名时才查，逻辑一致 */
    public function test_other_region_venue_is_queried_by_name(): void
    {
        $this->book('龙安湖', '14:00', '15:00');

        $this->assertSame(['07:00-14:00', '15:00-23:00'], $this->slots('龙安湖'));
        $this->assertSame(['07:00-23:00'], $this->slots('1A'), '开发区场地不受其它区域影响');
    }
}
