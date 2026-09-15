<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 约课入参校验
 *
 * - 时间必须落在场地开放时段（config doubao.booking.hours，默认 07:00-23:00）
 * - 指定场地时必须在白名单（config doubao.booking.venues）内
 * - 自动分配满场时，提示语里的场地列表来自配置，不是写死的
 */
class BookingValidationTest extends TestCase
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

    /** 开放时间之前不能约 */
    public function test_rejects_booking_before_opening_hours(): void
    {
        $result = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(6),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('开放时间是 07:00-23:00', $result['message']);
        $this->assertSame(0, BookingRecord::count());
    }

    /** 跨过打烊时间的课不能约（22:30-23:30） */
    public function test_rejects_booking_spanning_closing_time(): void
    {
        $result = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(22, 30),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('不在开放时段内', $result['message']);
        $this->assertSame(0, BookingRecord::count());
    }

    /** 边界时段可以约：07:00-08:00 与 22:00-23:00 */
    public function test_accepts_booking_at_opening_boundaries(): void
    {
        $morning = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(7),
        ]);
        $this->assertTrue($morning['success'], $morning['message']);

        $evening = $this->booking->create([
            'student_name' => '小红', 'coach_name' => '李教练', 'venue' => '2A', 'start_at' => $this->at(22),
        ]);
        $this->assertTrue($evening['success'], $evening['message']);

        $this->assertSame(2, BookingRecord::count());
    }

    /** 白名单之外的场地不能约（避免把「3号场地」这类场地写进库） */
    public function test_rejects_unknown_venue(): void
    {
        $result = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '3号场地', 'start_at' => $this->at(10),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('不存在', $result['message']);
        $this->assertStringContainsString('1A', $result['message']);
        $this->assertSame(0, BookingRecord::count());
    }

    /** 其它区域场地在白名单内，正常可约 */
    public function test_accepts_other_region_venue(): void
    {
        $result = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '龙安湖', 'start_at' => $this->at(15),
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('龙安湖', BookingRecord::first()->venue);
    }

    /** 改约到非法场地 / 开放时段之外同样拦截，原记录不被改动 */
    public function test_update_rejects_invalid_venue_and_hours(): void
    {
        $created = $this->booking->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'venue' => '1A', 'start_at' => $this->at(10),
        ]);
        $this->assertTrue($created['success']);

        $badVenue = $this->booking->update($created['booking']->id, ['venue' => '3号场地']);
        $this->assertFalse($badVenue['success']);
        $this->assertStringContainsString('不存在', $badVenue['message']);

        $badTime = $this->booking->update($created['booking']->id, ['start_at' => $this->at(23)]);
        $this->assertFalse($badTime['success']);
        $this->assertStringContainsString('不在开放时段内', $badTime['message']);

        $booking = $created['booking']->fresh();
        $this->assertSame('1A', $booking->venue);
        $this->assertSame($this->at(10), $booking->start_at->format('Y-m-d H:i'));
    }

    /** 自动分配满场时，提示语里的场地列表来自配置 */
    public function test_full_venue_message_lists_configured_venues(): void
    {
        foreach (['1A' => '王教练', '1B' => '李教练', '2A' => '赵教练', '2B' => '钱教练'] as $venue => $coach) {
            $created = $this->booking->create([
                'student_name' => '学员'.$venue, 'coach_name' => $coach, 'venue' => $venue, 'start_at' => $this->at(10),
            ]);
            $this->assertTrue($created['success'], $created['message']);
        }

        // 不指定场地 → 四个半场都满了
        $result = $this->booking->create([
            'student_name' => '小刚', 'coach_name' => '孙教练', 'venue' => '', 'start_at' => $this->at(10),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('1A/1B/2A/2B', $result['message']);
    }
}
