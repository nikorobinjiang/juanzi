<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ExcelService;
use App\Services\OverviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 约课记录展示窗口：只显示"当前周 + 未来三周"，历史记录不显示
 *
 * 覆盖：
 * - 约课页周列表（weekly / weeklyForApi）只含窗口内记录，本周已过去的日期仍属于"当前周"
 * - 豆包上下文（toJsonForAI）不含历史
 * - 排课查询（schedule）与展示窗口取交集
 * - Excel 导出只含窗口内的周
 * - 课时统计（上课次数 / 剩余课时）仍按全量口径，不受窗口影响
 */
class BookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // 固定在周三 10:00：本周一 = 2026-09-14，展示窗口 = 09-14 ~ 10-11
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

    private function makeBooking(string $student, string $datetime, array $attrs = []): BookingRecord
    {
        $startAt = Carbon::parse($datetime, 'Asia/Shanghai');

        return BookingRecord::create([
            'student_name' => $student,
            'coach_name' => '王教练',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHour(),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
            ...$attrs,
        ]);
    }

    /** 覆盖窗口边界：历史（上周）、本周已过去、窗口末日、窗口之外 */
    private function seedWindowRecords(): void
    {
        $this->makeBooking('上周学员', '2026-09-09 10:00');
        $this->makeBooking('本周学员', '2026-09-14 09:00');
        $this->makeBooking('周中学员', '2026-09-16 20:00');
        $this->makeBooking('下周学员', '2026-09-23 10:00');
        $this->makeBooking('第三周学员', '2026-09-30 10:00');
        $this->makeBooking('末周学员', '2026-10-11 23:00');
        $this->makeBooking('越界学员', '2026-10-12 09:00');
    }

    /** 周列表只含 4 周（当前周 + 未来三周），历史与越界记录都不出现 */
    public function test_weekly_only_contains_display_window_records(): void
    {
        $this->seedWindowRecords();

        $weeks = $this->booking->weekly();

        $this->assertSame([
            '9月14日-9月20日',
            '9月21日-9月27日',
            '9月28日-10月4日',
            '10月5日-10月11日',
        ], $weeks->pluck('label')->all());

        $this->assertSame([
            '本周学员',
            '周中学员',
            '下周学员',
            '第三周学员',
            '末周学员',
        ], $weeks->flatMap(fn (array $week) => $week['items']->pluck('student_name'))->values()->all());
    }

    /** 给豆包的上下文不含历史记录（历史不展示也不参与改课/取消） */
    public function test_to_json_for_ai_excludes_history(): void
    {
        $this->seedWindowRecords();

        $json = $this->booking->toJsonForAI();

        $this->assertStringContainsString('下周学员', $json);
        $this->assertStringContainsString('本周学员', $json);
        $this->assertStringNotContainsString('上周学员', $json);
        $this->assertStringNotContainsString('越界学员', $json);
    }

    /** 排课查询默认取展示窗口；显式查询历史区间同样查不到 */
    public function test_schedule_queries_are_clamped_to_window(): void
    {
        $this->seedWindowRecords();

        $this->assertSame(5, $this->booking->schedule()->count());

        // 默认查询（不传区间）不含历史
        $this->assertSame(0, $this->booking->schedule('上周学员')->count());

        // 显式查询历史区间：与窗口取交集后为空
        $this->assertSame(0, $this->booking->schedule(
            '',
            '',
            Carbon::parse('2026-09-07 00:00', 'Asia/Shanghai'),
            Carbon::parse('2026-09-13 00:00', 'Asia/Shanghai')
        )->count());
    }

    /** Excel 导出只生成窗口内的周页签（本周起 4 周） */
    public function test_excel_export_only_contains_window_weeks(): void
    {
        $this->seedWindowRecords();

        $result = app(ExcelService::class)->generate();

        $spreadsheet = IOFactory::load($result['path']);
        $titles = array_map(fn ($sheet) => $sheet->getTitle(), $spreadsheet->getAllSheets());

        $this->assertSame([
            '9月14日-9月20日',
            '9月21日-9月27日',
            '9月28日-10月4日',
            '10月5日-10月11日',
        ], $titles);

        @unlink($result['path']);
    }

    /** 学员详情的最近记录按窗口过滤，但课时统计仍按全量 */
    public function test_statistics_stay_full_scope_while_detail_list_is_windowed(): void
    {
        $student = Student::create([
            'name' => '小明',
            'coach_name' => '王教练',
            'lessons_total' => 10,
            'organization_code' => 'tennis_a',
        ]);

        // 历史 3 次（已完成）+ 窗口内 1 次
        $this->makeBooking('小明', '2026-08-03 10:00', ['status' => BookingRecord::STATUS_COMPLETED]);
        $this->makeBooking('小明', '2026-08-10 10:00', ['status' => BookingRecord::STATUS_COMPLETED]);
        $this->makeBooking('小明', '2026-08-17 10:00', ['status' => BookingRecord::STATUS_COMPLETED]);
        $this->makeBooking('小明', '2026-09-23 10:00');

        // 统计口径：全量（含历史）
        $this->assertSame(4, $this->booking->countLessons('小明'));

        $detail = app(OverviewService::class)->studentDetail($student->id);

        $this->assertSame(4, $detail['lesson_count']);
        $this->assertSame(6, $detail['lessons_remaining']);

        // 详情里的约课记录：只显示窗口内
        $this->assertCount(1, $detail['bookings']);
        $this->assertSame('2026-09-23 10:00', $detail['bookings'][0]['start_at']);
    }
}
