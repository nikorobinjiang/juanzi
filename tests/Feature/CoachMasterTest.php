<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\Coach;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CoachService;
use App\Services\CrmService;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 教练主数据表：名单归一、自动建档、改名同步
 */
class CoachMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'alice',
            'username' => 'alice',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]);

        $this->actingAs($this->user);
    }

    /**
     * 造跨机构档案
     *
     * 模型的 creating 钩子会把 organization_code 覆写成当前登录用户的机构，
     * 因此跨机构数据要跳过模型事件创建（等价于别的机构用户自己录入的数据）。
     */
    private function crossOrg(string $modelClass, array $attributes): mixed
    {
        return $modelClass::withoutEvents(fn () => $modelClass::create($attributes));
    }

    /** 别名/称谓归一：「王教练」「小王」都归到主档案的「王强」 */
    public function test_resolves_alias_and_title_to_canonical_name(): void
    {
        Coach::create([
            'organization_code' => 'tennis_a',
            'name' => '王强',
            'aliases' => ['小王', '王'],
        ]);

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $this->assertSame('王强', $coaches->resolveCoachName('王强'));
        $this->assertSame('王强', $coaches->resolveCoachName('小王'));
        $this->assertSame('王强', $coaches->resolveCoachName('王强教练'));

        // 没有档案时不改写用户的说法（别人的名字照原样保留）
        $this->assertSame('李老师', $coaches->resolveCoachName('李老师'));
    }

    /** 跨机构隔离：另一家机构的同名/别名档案不会被误归一 */
    public function test_resolution_is_scoped_by_organization(): void
    {
        $this->crossOrg(Coach::class, [
            'organization_code' => 'swim_a',
            'name' => '小李',
            'aliases' => ['李'],
        ]);

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $this->assertSame('小李', $coaches->resolveCoachName('李', 'swim_a'));
        $this->assertSame('李', $coaches->resolveCoachName('李', 'tennis_a'));
    }

    /** 新教练自动建档，重复建档不产生第二条 */
    public function test_ensure_coach_creates_profile_once(): void
    {
        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $first = $coaches->ensureCoach('张教练');
        $second = $coaches->ensureCoach('张教练');

        $this->assertNotNull($first);
        $this->assertSame('张教练', $first->name);
        $this->assertSame('tennis_a', $first->organization_code);

        $this->assertSame(1, Coach::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame($first->id, $second->id);
    }

    /** 约课入库前做归一：说「小王教练」落库也是主数据的「王强」 */
    public function test_booking_normalizes_coach_name(): void
    {
        Coach::create([
            'organization_code' => 'tennis_a',
            'name' => '王强',
            'aliases' => ['小王', '王'],
        ]);

        $result = app(BookingService::class)->create([
            'student_name' => '小明',
            'coach_name' => '小王教练',
            'venue' => '1A',
            'start_at' => Carbon::today()->setTime(10, 0)->toDateTimeString(),
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('王强', $result['booking']->coach_name);

        // 学员档案里的教练同口径归一
        $this->assertSame('王强', Student::where('name', '小明')->first()->coach_name);
        // 带课教练顺带进了主数据
        $this->assertTrue(app(CoachService::class)->hasProfile('王强'));
    }

    /** 约课遇到主数据里没有的新教练：自动建档 */
    public function test_booking_auto_creates_unknown_coach(): void
    {
        $result = app(BookingService::class)->create([
            'student_name' => '小红',
            'coach_name' => '陈教练',
            'venue' => '1B',
            'start_at' => Carbon::today()->setTime(15, 0)->toDateTimeString(),
        ]);

        $this->assertTrue($result['success'], $result['message']);

        $coach = Coach::withoutGlobalScope(OrganizationScope::class)->where('name', '陈教练')->first();
        $this->assertNotNull($coach);
        $this->assertSame('tennis_a', $coach->organization_code);
    }

    /** 改名：主数据更新 + 旧名进别名 + 三张业务表的字符串同步 */
    public function test_rename_syncs_master_and_business_tables(): void
    {
        Coach::create([
            'organization_code' => 'tennis_a',
            'name' => '孟',
            'aliases' => [],
        ]);

        BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '小明',
            'coach_name' => '孟',
            'start_at' => Carbon::today()->setTime(9, 0),
            'end_at' => Carbon::today()->setTime(10, 0),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
        ]);

        FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'venue' => '1A',
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'student_name' => '小明',
            'coach_name' => '孟',
        ]);

        Student::create([
            'organization_code' => 'tennis_a',
            'name' => '小明',
            'coach_name' => '孟',
            'lessons_total' => 0,
        ]);

        $count = app(FixedScheduleService::class)->renameCoach('孟', '孟宇');

        $this->assertSame(3, $count);
        $this->assertSame('孟宇', BookingRecord::first()->coach_name);
        $this->assertSame('孟宇', FixedSchedule::first()->coach_name);
        $this->assertSame('孟宇', Student::first()->coach_name);
        $this->assertSame('孟宇', Coach::first()->name);
        // 旧写法留下做别名，以后说「孟教练」也能归一到新名
        $this->assertContains('孟', Coach::first()->aliases_list);
    }

    /** 改名不越机构：另一家机构的同名教练不被误改 */
    public function test_rename_does_not_touch_other_organization(): void
    {
        $this->crossOrg(Coach::class, ['organization_code' => 'swim_a', 'name' => '孟', 'aliases' => []]);
        Coach::create(['organization_code' => 'tennis_a', 'name' => '孟', 'aliases' => []]);

        // 本机构的业务记录：改名得以它为事实依据
        Student::create([
            'organization_code' => 'tennis_a',
            'name' => '小刚',
            'coach_name' => '孟',
            'lessons_total' => 0,
        ]);

        $this->crossOrg(Student::class, [
            'organization_code' => 'swim_a',
            'name' => '小红',
            'coach_name' => '孟',
            'lessons_total' => 0,
        ]);

        app(FixedScheduleService::class)->renameCoach('孟', '孟宇');

        $this->assertSame('孟', Student::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', 'swim_a')->first()->coach_name);
        $this->assertSame('孟', Coach::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', 'swim_a')->first()->name);
        $this->assertSame('孟宇', Coach::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', 'tennis_a')->first()->name);
    }

    /** 给 AI 的名单来自主数据：停用（离职）教练不再出现，业务表写法做兜底 */
    public function test_context_coach_list_comes_from_master_data(): void
    {
        Coach::create(['organization_code' => 'tennis_a', 'name' => '王强', 'aliases' => ['小王']]);
        Coach::create(['organization_code' => 'tennis_a', 'name' => '离职教练', 'active' => false]);

        Student::create([
            'organization_code' => 'tennis_a',
            'name' => '小明',
            'coach_name' => '历史写法',
            'lessons_total' => 0,
        ]);

        $context = json_decode(app(CrmService::class)->crmContextJson(), true);

        $this->assertContains('王强', $context['coaches']);
        $this->assertContains('历史写法', $context['coaches']); // 主数据没建档时兜底带上
        $this->assertNotContains('离职教练', $context['coaches']);
        $this->assertArrayHasKey('students', $context); // 输出结构不变
    }

    /** coaches:sync 补漏 + 幂等 */
    public function test_sync_command_backfills_and_is_idempotent(): void
    {
        Coach::create(['organization_code' => 'tennis_a', 'name' => '已有教练', 'aliases' => []]);

        Student::create([
            'organization_code' => 'tennis_a',
            'name' => '小明',
            'coach_name' => '导入教练',
            'lessons_total' => 0,
        ]);

        $this->artisan('coaches:sync')->assertSuccessful();
        $this->assertTrue(app(CoachService::class)->hasProfile('导入教练'));

        $before = Coach::withoutGlobalScope(OrganizationScope::class)->count();
        $this->artisan('coaches:sync')->assertSuccessful();
        $this->assertSame($before, Coach::withoutGlobalScope(OrganizationScope::class)->count());
    }
}
