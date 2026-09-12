<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 场地"整场 / 半场"互斥
 *
 * 规则：
 * - 整场 1 = 1A + 1B；约了整场 1，1A/1B 都不能再约；约了 1A，整场 1 也不能约
 * - 1A 与 1B 之间不互斥；1 与 2 之间不互斥
 * - 场地空闲查询同样按上述规则统计
 */
class BookingVenueExclusiveTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = app(BookingService::class);

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));
    }

    private function at(int $hour, int $minute = 0): string
    {
        return Carbon::now('Asia/Shanghai')->addDays(2)->setTime($hour, $minute)->format('Y-m-d H:i');
    }

    /** 场地占位集合：整场展开为半场，半场关联整场，其它区域场地只占自身 */
    public function test_venue_slots_expansion(): void
    {
        $this->assertSame(['1', '1A', '1B'], $this->booking->venueSlots('1'));
        $this->assertSame(['2', '2A', '2B'], $this->booking->venueSlots('2'));
        $this->assertSame(['1A', '1'], $this->booking->venueSlots('1A'));
        $this->assertSame(['2B', '2'], $this->booking->venueSlots('2B'));
        $this->assertSame(['龙安湖'], $this->booking->venueSlots('龙安湖'));
    }

    /** 整场被占用时，两个半场都不可约 */
    public function test_full_court_blocks_halves(): void
    {
        $full = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1', 'start_at' => $this->at(10),
        ]);
        $this->assertTrue($full['success']);

        foreach (['1A', '1B'] as $half) {
            $result = $this->booking->create([
                'student_name' => '小红', 'coach_name' => '李教练', 'venue' => $half, 'start_at' => $this->at(10),
            ]);

            $this->assertFalse($result['success'], $half.' 应该因整场被占用而失败');
            $this->assertStringContainsString('场地冲突', $result['message']);
        }
    }

    /** 半场被占用时，整场不可约 */
    public function test_half_court_blocks_full(): void
    {
        $half = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1B', 'start_at' => $this->at(14),
        ]);
        $this->assertTrue($half['success']);

        $result = $this->booking->create([
            'student_name' => '小红', 'coach_name' => '李教练', 'venue' => '1', 'start_at' => $this->at(14),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('场地冲突', $result['message']);
    }

    /** 两半场之间、两整场之间互不冲突 */
    public function test_halves_and_other_courts_are_independent(): void
    {
        $first = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(9),
        ]);
        $this->assertTrue($first['success']);

        // 同一时间的 1B：不冲突（换个教练避免教练冲突干扰断言）
        $second = $this->booking->create([
            'student_name' => '小红', 'coach_name' => '李教练', 'venue' => '1B', 'start_at' => $this->at(9),
        ]);
        $this->assertTrue($second['success']);

        // 同一时间的 2 号整场：同样不冲突
        $third = $this->booking->create([
            'student_name' => '小刚', 'coach_name' => '赵教练', 'venue' => '2', 'start_at' => $this->at(9),
        ]);
        $this->assertTrue($third['success']);

        // 附近时间的 1 号整场：不冲突（14:00-15:00 与 9:00-10:00 无重叠）
        $fourth = $this->booking->create([
            'student_name' => '小美', 'coach_name' => '钱教练', 'venue' => '1', 'start_at' => $this->at(14),
        ]);
        $this->assertTrue($fourth['success']);
    }

    /** 修改场地时同样按整场/半场互斥拦截 */
    public function test_update_venue_respects_exclusive_rule(): void
    {
        $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '2', 'start_at' => $this->at(16),
        ]);

        $target = $this->booking->create([
            'student_name' => '小红', 'coach_name' => '李教练', 'venue' => '1A', 'start_at' => $this->at(16),
        ]);
        $this->assertTrue($target['success']);

        // 把 1A 的课改到 2 号整场 → 与已有记录冲突
        $result = $this->booking->update($target['booking']->id, ['venue' => '2']);

        $this->assertFalse($result['success']);
        $this->assertSame('1A', $target['booking']->fresh()->venue);
    }

    /** 场地空闲查询：整场查询会把半场占用计入 */
    public function test_venue_availability_counts_half_court_usage(): void
    {
        $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(10),
        ]);

        $day = Carbon::now('Asia/Shanghai')->addDays(2);
        $slots = collect($this->booking->venueAvailability('1', $day, $day))->first()['slots'];

        $this->assertNotContains('10:00-11:00', $slots);
        $this->assertContains('11:00-12:00', $slots);

        // 2 号整场不受影响
        $courts = collect($this->booking->venueAvailability('2', $day, $day))->first()['slots'];
        $this->assertContains('10:00-11:00', $courts);

        // 1B 不受 1A 占用影响（1A 与 1B 之间不互斥）
        $halfSlots = collect($this->booking->venueAvailability('1B', $day, $day))->first()['slots'];
        $this->assertContains('10:00-11:00', $halfSlots);

        // 记录确实落在 1A
        $this->assertSame('1A', BookingRecord::first()->venue);
    }
}
