<?php

namespace Tests\Feature;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\CoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 教练档案 ↔ 登录账号：绑定、占用校验、自动匹配与维护命令
 */
class CoachUserLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // 前台/管理员：录入教练档案的往往是这个账号，不能因为它录的就把教练绑给它
        $this->manager = User::create([
            'name' => 'admin',
            'username' => 'admin',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]);

        $this->actingAs($this->manager);
    }

    /** 自动建档的教练不绑任何账号（录档案的是前台，不是教练本人） */
    public function test_auto_created_coach_is_not_bound_to_operator(): void
    {
        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $coach = $coaches->ensureCoach('李教练');

        $this->assertNotNull($coach);
        $this->assertNull($coach->user_id);
        $this->assertNull($coach->fresh()->user_id);
        $this->assertNull($coach->fresh()->user);
    }

    /** 绑定后双向关系都能取到 */
    public function test_links_coach_with_user_and_reads_relation(): void
    {
        $coach = $this->makeCoach('孟宇');
        $user = $this->makeUser('mengyu', '孟宇');

        $result = app(CoachService::class)->linkUser($coach, $user);

        $this->assertSame(CoachService::LINK_LINKED, $result['status']);
        $this->assertSame($user->id, $coach->fresh()->user_id);
        $this->assertSame('mengyu', $coach->fresh()->user->username);
        $this->assertSame('孟宇', $user->fresh()->coach->name);
    }

    /** 跨机构账号不允许绑定 */
    public function test_link_rejects_cross_organization_user(): void
    {
        $coach = $this->makeCoach('孟宇');
        $outsider = $this->makeUser('outsider', '孟宇', 'swim_a');

        $result = app(CoachService::class)->linkUser($coach, $outsider);

        $this->assertSame(CoachService::LINK_CROSS_ORG, $result['status']);
        $this->assertNull($coach->fresh()->user_id);
    }

    /** 一个账号不能被两位教练同时占用 */
    public function test_link_rejects_user_already_taken(): void
    {
        $first = $this->makeCoach('孟宇');
        $second = $this->makeCoach('小李');
        $user = $this->makeUser('mengyu', '孟宇');

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $this->assertSame(CoachService::LINK_LINKED, $coaches->linkUser($first, $user)['status']);

        $result = $coaches->linkUser($second, $user);

        $this->assertSame(CoachService::LINK_TAKEN, $result['status']);
        $this->assertStringContainsString('孟宇', $result['message']);
        $this->assertNull($second->fresh()->user_id);
    }

    /** 已绑定时不允许静默改绑，force 或先解绑才行 */
    public function test_relink_requires_force_or_unlink(): void
    {
        $coach = $this->makeCoach('孟宇');
        $firstUser = $this->makeUser('u1', '甲');
        $secondUser = $this->makeUser('u2', '乙');

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $this->assertSame(CoachService::LINK_LINKED, $coaches->linkUser($coach, $firstUser)['status']);

        $blocked = $coaches->linkUser($coach, $secondUser);

        $this->assertSame(CoachService::LINK_BOUND_OTHER, $blocked['status']);
        $this->assertSame($firstUser->id, $coach->fresh()->user_id);

        $this->assertSame(CoachService::LINK_LINKED, $coaches->linkUser($coach, $secondUser, true)['status']);
        $this->assertSame($secondUser->id, $coach->fresh()->user_id);

        $this->assertSame(CoachService::LINK_UNLINKED, $coaches->unlinkUser($coach)['status']);
        $this->assertNull($coach->fresh()->user_id);
        $this->assertNull($coach->fresh()->user);
    }

    /** 重复绑同一个账号幂等，解绑已解绑的也幂等 */
    public function test_link_and_unlink_are_idempotent(): void
    {
        $coach = $this->makeCoach('孟宇');
        $user = $this->makeUser('mengyu', '孟宇');

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $coaches->linkUser($coach, $user);

        $this->assertSame(CoachService::LINK_ALREADY, $coaches->linkUser($coach, $user)['status']);
        $this->assertSame(CoachService::LINK_UNLINKED, $coaches->unlinkUser($coach)['status']);
        $this->assertSame(CoachService::LINK_ALREADY, $coaches->unlinkUser($coach)['status']);
    }

    /** 自动匹配：昵称命中与登录名命中都能识别 */
    public function test_preview_matches_by_nickname_and_username(): void
    {
        $this->makeCoach('孟宇');
        $this->makeUser('mengyu', '孟宇');

        $this->makeCoach('wanghao');
        $this->makeUser('wanghao', '昵称与教练名无关');

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $report = collect($coaches->previewLinks('tennis_a'));

        $this->assertSame(
            ['wanghao', '孟宇'],
            $report->filter(fn (array $row) => $row['status'] === CoachService::LINK_MATCHED)
                ->map(fn (array $row) => $row['coach']->name)
                ->all()
        );

        // 预览只算不算写
        $this->assertNull(Coach::withoutGlobalScope(OrganizationScope::class)->where('name', '孟宇')->first()->user_id);
    }

    /** 多个同名候选与无候选都要标出来，交给人工指定 */
    public function test_preview_flags_multi_match_and_no_match(): void
    {
        $this->makeCoach('王浩');
        $this->makeUser('wang1', '王浩');
        $this->makeUser('wang2', '王浩');

        $this->makeCoach('查无此人');

        /** @var CoachService $coaches */
        $coaches = app(CoachService::class);

        $report = collect($coaches->previewLinks('tennis_a'))->keyBy(fn (array $row) => $row['coach']->name);

        $this->assertSame(CoachService::LINK_MULTI_MATCH, $report['王浩']['status']);
        $this->assertCount(2, $report['王浩']['candidates']);

        $this->assertSame(CoachService::LINK_NO_MATCH, $report['查无此人']['status']);
        $this->assertTrue($report['查无此人']['candidates']->isEmpty());
    }

    /** 已被别的教练占用的账号不会出现在可绑定候选里 */
    public function test_preview_marks_taken_user(): void
    {
        // 同一位教练被建成了两份档案：「王强」先绑走了账号 wang，
        // 「wang」这份档案再匹配到同一账号时只能标记为被占用
        $owner = $this->makeCoach('王强');
        $this->makeCoach('wang');
        $user = $this->makeUser('wang', '王强');

        app(CoachService::class)->linkUser($owner, $user);

        $report = collect(app(CoachService::class)->previewLinks('tennis_a'))
            ->keyBy(fn (array $row) => $row['coach']->name);

        $this->assertSame(CoachService::LINK_TAKEN, $report['wang']['status']);
        $this->assertStringContainsString('王强', $report['wang']['message']);
        $this->assertNull($report['wang']['coach']->user_id);
    }

    /** 批量命令：dry-run 不写库，去掉后才真正绑定 */
    public function test_batch_command_binds_after_dry_run_preview(): void
    {
        $this->makeCoach('孟宇');
        $user = $this->makeUser('mengyu', '孟宇');

        $this->artisan('coaches:link', ['--org' => 'tennis_a', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($this->freshCoach('孟宇')->user_id);

        $this->artisan('coaches:link', ['--org' => 'tennis_a'])
            ->assertSuccessful();

        $this->assertSame($user->id, $this->freshCoach('孟宇')->user_id);
    }

    /** 手工命令：按别名找教练，--user 支持登录名与用户 id，--unlink 解除 */
    public function test_manual_command_links_and_unlinks(): void
    {
        $coach = $this->makeCoach('孟宇', ['小孟']);
        $user = $this->makeUser('mengyu', '昵称无关');

        $this->artisan('coaches:link', ['--coach' => '小孟', '--user' => 'mengyu', '--org' => 'tennis_a'])
            ->assertSuccessful();

        $this->assertSame($user->id, $coach->fresh()->user_id);

        $this->artisan('coaches:link', ['--coach' => '孟宇', '--user' => (string) $user->id, '--org' => 'tennis_a', '--force' => true])
            ->assertSuccessful();

        $this->assertSame($user->id, $coach->fresh()->user_id);

        $this->artisan('coaches:link', ['--coach' => '孟宇', '--unlink' => true, '--org' => 'tennis_a'])
            ->assertSuccessful();

        $this->assertNull($coach->fresh()->user_id);
    }

    /** 手工绑定跨机构账号会被拒绝并给出失败退出码 */
    public function test_manual_command_rejects_cross_organization(): void
    {
        $this->makeCoach('孟宇');
        $this->makeUser('outsider', '孟宇', 'swim_a');

        $this->artisan('coaches:link', ['--coach' => '孟宇', '--user' => 'outsider', '--org' => 'tennis_a'])
            ->assertFailed();

        $this->assertNull($this->freshCoach('孟宇')->user_id);
    }

    private function makeCoach(string $name, array $aliases = []): Coach
    {
        return Coach::create([
            'organization_code' => 'tennis_a',
            'name' => $name,
            'aliases' => $aliases,
        ]);
    }

    private function makeUser(string $username, string $name, string $org = 'tennis_a'): User
    {
        return User::withoutEvents(fn () => User::create([
            'name' => $name,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
        ]));
    }

    private function freshCoach(string $name): Coach
    {
        return Coach::withoutGlobalScope(OrganizationScope::class)->where('name', $name)->first();
    }
}
