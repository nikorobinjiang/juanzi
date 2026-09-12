<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\User;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 固定课表导入：模板展开为未来若干周的预约记录
 *
 * 覆盖：
 * - 逐周生成（每周同一天同时刻各一条）
 * - 幂等（重复执行不产生重复记录）
 * - 冲突跳过（不覆盖已有记录）
 * - 拼场（1A/1B 同时间共存）与整场「1」互斥
 * - 表内重叠照录（同一片场地同一时间多场次时按表照录，不丢数据）
 * - 学员自动建档、用途场不建档
 */
class FixedScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    private FixedScheduleService $service;

    /** 起始周一：下一个周一，避免"已过去"的时段被跳过导致断言不稳定 */
    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FixedScheduleService::class);
        $this->start = Carbon::now('Asia/Shanghai')->startOfWeek(Carbon::MONDAY)->addWeek();

        // 让导入命令的默认起始日/周数跟随测试时间，避免依赖真实日期
        config([
            'doubao.fixed_schedule.start' => $this->start->format('Y-m-d'),
            'doubao.fixed_schedule.weeks' => 1,
        ]);
    }

    private function makeUser(string $username, string $org): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
        ]);
    }

    /** 建一条固定场模板 */
    private function makeTemplate(array $attrs = []): FixedSchedule
    {
        return FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1',
            'weekday' => 1,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'student_name' => '小明',
            'coach_name' => '张',
            'exclusive' => true,
            'is_student' => true,
            ...$attrs,
        ]);
    }

    /** 模板按周展开：每个模板每周生成一条记录，且带 fixed_schedule_id 便于追溯 */
    public function test_materialize_expands_templates_week_by_week(): void
    {
        $this->makeTemplate();
        $this->makeTemplate([
            'region' => FixedSchedule::REGION_LONGANHU,
            'venue' => '龙安湖',
            'weekday' => 2,
            'start_time' => '19:00',
            'end_time' => '20:30',
            'student_name' => '小红',
            'exclusive' => false,
        ]);

        $result = $this->service->materialize(3, 'tennis_a', $this->start);

        $this->assertSame(6, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['conflicts']);

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
        $this->assertCount(6, $records);

        // 第一周的两条：周一 10:00 场地 1；周二 19:00 龙安湖
        $this->assertSame($this->start->format('Y-m-d').' 10:00', $records[0]->start_at->format('Y-m-d H:i'));
        $this->assertSame('1', $records[0]->venue);
        $this->assertSame($this->start->copy()->addDay()->format('Y-m-d').' 19:00', $records[1]->start_at->format('Y-m-d H:i'));
        $this->assertSame('龙安湖', $records[1]->venue);
        $this->assertSame('20:30', $records[1]->end_at->format('H:i'));

        // 三周都有
        $this->assertSame(
            3,
            $records->filter(fn ($r) => $r->start_at->format('H:i') === '10:00')->count()
        );

        $this->assertTrue($records->every(fn ($r) => $r->fixed_schedule_id !== null));
        $this->assertTrue($records->every(fn ($r) => $r->status === BookingRecord::STATUS_BOOKED));
        $this->assertTrue($records->every(fn ($r) => $r->organization_code === 'tennis_a'));
    }

    /** 幂等：重复执行只补生成缺失的记录，不产生重复 */
    public function test_materialize_is_idempotent(): void
    {
        $this->makeTemplate();

        $first = $this->service->materialize(3, 'tennis_a', $this->start);
        $second = $this->service->materialize(3, 'tennis_a', $this->start);

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['skipped']);

        $this->assertSame(3, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());

        // 扩展到 4 周时只补第 4 周那一条
        $third = $this->service->materialize(4, 'tennis_a', $this->start);
        $this->assertSame(1, $third['created']);
        $this->assertSame(4, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /** 与已有记录冲突：跳过并报告，不覆盖原记录 */
    public function test_materialize_skips_conflicting_slots(): void
    {
        $this->makeTemplate(); // 周一 10:00-11:00 整场 1

        // 已有记录：同一周一 10:30 占了 1A（与整场 1 互斥）
        $existing = BookingRecord::create([
            'student_name' => '老学员',
            'coach_name' => '李教练',
            'start_at' => $this->start->copy()->setTime(10, 30),
            'end_at' => $this->start->copy()->setTime(11, 30),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'organization_code' => 'tennis_a',
        ]);

        $result = $this->service->materialize(1, 'tennis_a', $this->start);

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertStringContainsString('1A', $result['conflicts'][0]['conflict_with']);

        // 原记录未被改动，也没多出记录
        $this->assertSame(1, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame('老学员', $existing->fresh()->student_name);
        $this->assertSame('1A', $existing->fresh()->venue);
    }

    /** 表内自身重叠（同一格两人同场，如周一 1 号场的 岩儿/吕江涵）：默认照录并报告，不丢数据 */
    public function test_materialize_keeps_self_overlaps_by_default(): void
    {
        $this->makeTemplate(['student_name' => '岩儿', 'coach_name' => '余', 'exclusive' => false]);
        $this->makeTemplate(['student_name' => '吕江涵', 'coach_name' => '徐', 'exclusive' => false]);

        $result = $this->service->materialize(1, 'tennis_a', $this->start);

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['conflicts']);
        $this->assertCount(1, $result['overlaps']);
        $this->assertSame('岩儿/余', $result['overlaps'][0]['overlap_with']);

        $this->assertSame(2, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /** 关闭表内重叠照录时，第二条按冲突跳过（严格模式） */
    public function test_materialize_can_skip_self_overlaps_when_disabled(): void
    {
        $this->makeTemplate(['student_name' => '岩儿', 'coach_name' => '余', 'exclusive' => false]);
        $this->makeTemplate(['student_name' => '吕江涵', 'coach_name' => '徐', 'exclusive' => false]);

        $result = $this->service->materialize(1, 'tennis_a', $this->start, false);

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame([], $result['overlaps']);
        $this->assertSame(1, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /** 拼场：1A 与 1B 同时间各自可约（拼场），但整场「1」与半场互斥 */
    public function test_materialize_allows_half_court_sharing(): void
    {
        $this->makeTemplate(['venue' => '1A', 'student_name' => '拼场甲', 'exclusive' => false]);
        $this->makeTemplate(['venue' => '1B', 'student_name' => '拼场乙', 'exclusive' => false]);

        $result = $this->service->materialize(1, 'tennis_a', $this->start);

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame([], $result['overlaps']);

        // 整场「1」与已占的 1A/1B 互斥
        $this->makeTemplate(['venue' => '1', 'student_name' => '整场丙']);

        $full = $this->service->materialize(1, 'tennis_a', $this->start);

        $this->assertSame(0, $full['created']);
        $this->assertCount(1, $full['conflicts']);
        $this->assertSame(2, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /** 用途场（不拼/非学员）同样落记录，但备注标记用途场 */
    public function test_usage_slot_is_recorded_with_remark(): void
    {
        $this->makeTemplate([
            'student_name' => '周例会',
            'coach_name' => '',
            'is_student' => false,
            'venue' => '2',
            'weekday' => 3,
            'start_time' => '12:00',
            'end_time' => '13:00',
        ]);

        $result = $this->service->materialize(1, 'tennis_a', $this->start);

        $this->assertSame(1, $result['created']);

        $record = BookingRecord::withoutGlobalScope(OrganizationScope::class)->first();
        $this->assertSame('周例会', $record->student_name);
        $this->assertStringContainsString('用途场', (string) $record->remark);
    }

    /** 导入命令：dry-run 不写库；正式导入落模板 + 记录，且只为学员建档 */
    public function test_import_command_dry_run_then_import(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        $file = tempnam(sys_get_temp_dir(), 'fixed').'.php';
        file_put_contents($file, '<?php return '.var_export([
            [
                'region' => '开发区场地', 'venue' => '1', 'weekday' => 1,
                'start_time' => '7.30', 'end_time' => '8.30',
                'student_name' => '小明', 'coach_name' => '张', 'exclusive' => true,
            ],
            [
                'region' => '开发区场地', 'venue' => '2', 'weekday' => 1,
                'start_time' => '9:00', 'end_time' => '10:30',
                'student_name' => '裘总用场', 'coach_name' => '', 'is_student' => false,
            ],
        ], true).';');

        try {
            // dry-run：只输出核对清单，不写库
            $this->artisan('fixed-schedules:import', [
                '--dry-run' => true, '--org' => 'tennis_a', '--weeks' => 1, '--file' => $file,
            ])->assertExitCode(0);

            $this->assertSame(0, FixedSchedule::withoutGlobalScope(OrganizationScope::class)->count());
            $this->assertSame(0, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
            $this->assertFileExists(storage_path('app/fixed-schedules-review.md'));

            // 正式导入
            $this->artisan('fixed-schedules:import', [
                '--org' => 'tennis_a', '--weeks' => 1, '--file' => $file,
            ])->assertExitCode(0);

            $templates = FixedSchedule::withoutGlobalScope(OrganizationScope::class)->get();
            $this->assertCount(2, $templates);
            $this->assertSame('07:30', $templates->firstWhere('student_name', '小明')->start_time);

            $this->assertSame(2, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());

            // 只给学员建档：用途场不建
            $this->assertSame(1, Student::withoutGlobalScope(OrganizationScope::class)->count());
            $this->assertSame('小明', Student::first()->name);
            $this->assertSame('张', Student::first()->coach_name);
            $this->assertSame(0, (int) Student::first()->lessons_total);
        } finally {
            @unlink($file);
        }
    }

    /** 重复导入同一份数据：模板走更新不重复，记录不重复 */
    public function test_import_command_is_idempotent(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        $file = tempnam(sys_get_temp_dir(), 'fixed').'.php';
        file_put_contents($file, '<?php return '.var_export([
            [
                'region' => '开发区场地', 'venue' => '1', 'weekday' => 1,
                'start_time' => '10:00', 'end_time' => '11:00',
                'student_name' => '小明', 'coach_name' => '张',
            ],
        ], true).';');

        try {
            $this->artisan('fixed-schedules:import', ['--org' => 'tennis_a', '--weeks' => 2, '--file' => $file])->assertExitCode(0);
            $this->artisan('fixed-schedules:import', ['--org' => 'tennis_a', '--weeks' => 2, '--file' => $file])->assertExitCode(0);

            $this->assertSame(1, FixedSchedule::withoutGlobalScope(OrganizationScope::class)->count());
            $this->assertSame(2, BookingRecord::withoutGlobalScope(OrganizationScope::class)->count());
        } finally {
            @unlink($file);
        }
    }
}
