<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\Coach;
use App\Models\User;
use App\Services\CoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台教练管理：建档 / 编辑改名 / 停用 / 绑定账号 / 删除保护
 */
class AdminCoachTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = User::create([
            'name' => 'root', 'username' => 'root', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_ADMIN,
        ]);

        $this->boss = User::create([
            'name' => 'boss', 'username' => 'boss', 'password' => 'secret123',
            'organization_code' => 'alan_tennis', 'role' => User::ROLE_ORG_ADMIN,
        ]);
    }

    private function makeCoach(string $name, string $org = 'tennis_a'): Coach
    {
        return Coach::withoutGlobalScopes()->create([
            'organization_code' => $org,
            'name' => $name,
            'active' => true,
        ]);
    }

    /** 教练档案新建 / 编辑 / 停用 */
    public function test_coach_crud_and_active_toggle(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/coaches', [
            'name' => '孟宇', 'phone' => '13700002222', 'aliases' => '小王、王教练',
        ])->assertCreated()->json('coach.id');

        $coach = Coach::withoutGlobalScopes()->find($id);

        $this->assertSame('tennis_a', $coach->organization_code);
        $this->assertSame(['小王', '王教练'], $coach->aliases_list);

        $this->postJson('/api/admin/coaches/'.$id.'/active', ['active' => false])
            ->assertOk()
            ->assertJsonPath('coach.active', false);

        $this->assertFalse($coach->fresh()->active);

        // 停用后不进 AI 名单
        $this->assertNotContains('孟宇', app(CoachService::class)->activeNames('tennis_a'));
    }

    /** 同名教练不允许重复建档 */
    public function test_duplicate_coach_name_is_rejected(): void
    {
        $this->actingAs($this->root);

        $this->postJson('/api/admin/coaches', ['name' => '孟宇'])->assertCreated();
        $this->postJson('/api/admin/coaches', ['name' => '孟宇'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /** 改名会同步业务表里的 coach_name */
    public function test_renaming_coach_syncs_business_tables(): void
    {
        $this->actingAs($this->root);

        $coach = $this->makeCoach('孟宇');

        BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '王小明',
            'coach_name' => '孟宇',
            'status' => BookingRecord::STATUS_BOOKED,
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'venue' => '1号场',
        ]);

        $this->putJson('/api/admin/coaches/'.$coach->id, ['name' => '孟宇老师'])->assertOk();

        $this->assertSame('孟宇老师', $coach->fresh()->name);
        $this->assertSame('孟宇老师', BookingRecord::where('organization_code', 'tennis_a')->value('coach_name'));
        $this->assertContains('孟宇', $coach->fresh()->aliases_list);
    }

    /** 绑定与解绑登录账号 */
    public function test_links_and_unlinks_user_account(): void
    {
        $this->actingAs($this->root);

        $coach = $this->makeCoach('孟宇');

        $user = User::create([
            'name' => '孟宇', 'username' => 'mengyu', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_USER,
        ]);

        $this->postJson('/api/admin/coaches/'.$coach->id.'/user', ['username' => 'mengyu'])
            ->assertOk()
            ->assertJsonPath('coach.username', 'mengyu');

        $this->assertSame($user->id, $coach->fresh()->user_id);

        // 已被占用的账号要报错
        $other = $this->makeCoach('小李');
        $this->postJson('/api/admin/coaches/'.$other->id.'/user', ['username' => 'mengyu'])
            ->assertUnprocessable();

        $this->deleteJson('/api/admin/coaches/'.$coach->id.'/user')->assertOk();
        $this->assertNull($coach->fresh()->user_id);
    }

    /** 有业务引用时要求强制删除，否则提示先停用 */
    public function test_delete_requires_force_when_referenced(): void
    {
        $this->actingAs($this->root);

        $coach = $this->makeCoach('孟宇');

        BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '王小明',
            'coach_name' => '孟宇',
            'status' => BookingRecord::STATUS_BOOKED,
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'venue' => '1号场',
        ]);

        $this->deleteJson('/api/admin/coaches/'.$coach->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coach');

        $this->assertNotNull($coach->fresh());

        $this->deleteJson('/api/admin/coaches/'.$coach->id.'?force=1')->assertOk();
        $this->assertNull($coach->fresh());
    }

    /** 没有引用的教练可直接删除 */
    public function test_delete_coach_without_reference(): void
    {
        $this->actingAs($this->root);

        $coach = $this->makeCoach('临时教练');

        $this->deleteJson('/api/admin/coaches/'.$coach->id)->assertOk();
        $this->assertNull($coach->fresh());
    }

    /** 机构隔离：机构管理员看不到也改不动别的机构的教练 */
    public function test_coaches_are_scoped_by_organization(): void
    {
        $foreign = $this->makeCoach('外来教练', 'tennis_a');
        $mine = $this->makeCoach('本馆教练', 'alan_tennis');

        $this->actingAs($this->boss);

        $this->getJson('/api/admin/coaches')
            ->assertOk()
            ->assertJsonPath('organization_code', 'alan_tennis')
            ->assertJsonCount(1, 'coaches');

        $this->putJson('/api/admin/coaches/'.$foreign->id, ['name' => '改名了'])->assertUnprocessable();
        $this->deleteJson('/api/admin/coaches/'.$foreign->id)->assertUnprocessable();

        $this->assertSame('外来教练', $foreign->fresh()->name);
        $this->assertSame('本馆教练', $mine->fresh()->name);
    }

    /** 总管理员切换机构后管理的是新机构的教练 */
    public function test_super_admin_manages_coaches_of_switched_org(): void
    {
        $this->makeCoach('阿蓝教练', 'swim_a');

        $this->actingAs($this->root);

        $this->getJson('/api/admin/coaches')->assertOk()->assertJsonCount(0, 'coaches');

        $this->postJson('/api/admin/switch-organization', ['organization_code' => 'swim_a'])->assertOk();

        $this->getJson('/api/admin/coaches')
            ->assertOk()
            ->assertJsonPath('organization_code', 'swim_a')
            ->assertJsonCount(1, 'coaches');
    }
}
