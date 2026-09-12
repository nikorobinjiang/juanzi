<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\FixedScheduleService;
use App\Support\BookingWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 固定场滚动窗口：只展开"本周 + 下周"
 *
 * 覆盖：
 * - ensureWindow 以本周一为起点：覆盖本周剩余 + 下周整周
 * - 已经过去的时段不生成（不会补出历史记录）
 * - 幂等：重复调用不重复插入
 * - syncFuture（模板改动重算）同样以窗口起点展开，不会漏掉本周、也不会越出窗口
 */
class FixedScheduleWindowTest extends TestCase
{
    use RefreshDatabase;

    private FixedScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 固定在周三 10:00：能同时区分"本周已过去 / 本周剩余 / 下周"
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'Asia/Shanghai'));

        config([
            'doubao.fixed_schedule.weeks' => 2,
            'doubao.window.weeks' => 4,
            'doubao.fixed_schedule.start' => '',
        ]);

        $this->service = app(FixedScheduleService::class);

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));
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

    /** @return \Illuminate\Support\Collection<int, BookingRecord> */
    private function records()
    {
        return BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
    }

    /** 配置默认值：固定场只展开 2 周（本周 + 下周），约课记录展示 4 周（当前周 + 未来三周） */
    public function test_window_config_defaults(): void
    {
        $config = require config_path('doubao.php');

        $this->assertSame(2, $config['fixed_schedule']['weeks']);
        $this->assertSame(4, $config['window']['weeks']);
    }

    /** 补齐窗口 = 本周一 ~ 下周日：本周已过去的场次不生成，本周剩余 + 下周都会生成 */
    public function test_ensure_window_covers_current_and_next_week_only(): void
    {
        // 今天 09:00-10:00 的场次（10:00 已结束）→ 不补历史
        $this->makeTemplate(['start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '已过去']);
        $this->makeTemplate(['weekday' => 5, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '本周五']);
        $this->makeTemplate(['student_name' => '今晚']);

        $result = $this->service->ensureWindow('tennis_a');

        // 今晚(本周三 20:00) + 本周五 + 下周三 09:00 + 下周三 20:00 + 下周五 = 5 条
        $this->assertSame(5, $result['created']);
        $this->assertSame([], $result['conflicts']);

        $records = $this->records();
        $this->assertSame([
            '今晚@2026-09-16 20:00',
            '本周五@2026-09-18 10:00',
            '已过去@2026-09-23 09:00',
            '今晚@2026-09-23 20:00',
            '本周五@2026-09-25 10:00',
        ], $records->map(fn ($r) => $r->student_name.'@'.$r->start_at->format('Y-m-d H:i'))->all());

        // 不会补出历史记录，也不会越出固定场窗口
        $this->assertTrue($records->every(fn ($r) => $r->start_at->gte(BookingWindow::now())));
        $this->assertTrue($records->every(fn ($r) => $r->start_at->lt(BookingWindow::fixedEnd())));
    }

    /** 幂等：重复补齐不重复插入 */
    public function test_ensure_window_is_idempotent(): void
    {
        $this->makeTemplate();

        $first = $this->service->ensureWindow('tennis_a');
        $this->assertSame(2, $first['created']);

        $second = $this->service->ensureWindow('tennis_a');
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, $this->records()->count());
    }

    /** 没有机构上下文（未登录且未显式传入）时不做事，避免误写别人的数据 */
    public function test_ensure_window_without_org_does_nothing(): void
    {
        $this->makeTemplate();

        auth('web')->logout();

        $result = $this->service->ensureWindow('');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $this->records()->count());
    }

    /** 模板改动重算：只覆盖固定场窗口，历史不受影响、也不会多展开一周 */
    public function test_sync_future_regenerates_within_window(): void
    {
        $template = $this->makeTemplate();
        $this->service->ensureWindow('tennis_a');
        $this->assertSame(2, $this->records()->count());

        $result = $this->service->syncFuture($template, ['start_time' => '21:00', 'end_time' => '22:00']);

        $this->assertSame('21:00', $template->fresh()->start_time);
        $this->assertSame(2, $result['deleted']);

        $records = $this->records();
        $this->assertSame([
            '2026-09-16 21:00',
            '2026-09-23 21:00',
        ], $records->map(fn ($r) => $r->start_at->format('Y-m-d H:i'))->all());
        $this->assertTrue($records->every(fn ($r) => $r->start_at->lt(BookingWindow::fixedEnd())));
    }
}
