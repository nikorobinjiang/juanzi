<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 后台用户管理：新建 / 编辑 / 重置密码 / 角色指派 / 删除，以及各类保护规则
 */
class AdminUserTest extends TestCase
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

    /** 总管理员在本机构新建账号，默认普通用户 */
    public function test_creates_user_with_default_role(): void
    {
        $this->actingAs($this->root);

        $response = $this->postJson('/api/admin/users', [
            'username' => 'zhangsan',
            'name' => '张三',
            'password' => 'pass1234',
        ]);

        $response->assertCreated()->assertJsonPath('user.role', User::ROLE_USER);

        $user = User::where('username', 'zhangsan')->first();

        $this->assertSame('tennis_a', $user->organization_code);
        $this->assertTrue(Hash::check('pass1234', $user->password));
    }

    /** 同机构登录名重复要报 422 */
    public function test_duplicate_username_is_rejected(): void
    {
        $this->actingAs($this->root);
        $this->postJson('/api/admin/users', ['username' => 'dup', 'password' => 'pass1234'])->assertCreated();
        $this->postJson('/api/admin/users', ['username' => 'dup', 'password' => 'pass1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    /** 机构管理员建账号只能落在本机构，且不能建管理员 */
    public function test_org_admin_creates_only_normal_user_in_own_org(): void
    {
        $this->actingAs($this->boss);

        $response = $this->postJson('/api/admin/users', [
            'username' => 'lisi',
            'password' => 'pass1234',
            'role' => User::ROLE_ORG_ADMIN,
        ]);

        $response->assertCreated();

        $user = User::where('username', 'lisi')->first();

        $this->assertSame('alan_tennis', $user->organization_code);
        $this->assertSame(User::ROLE_USER, $user->currentRole());
    }

    /** 重置密码后可以用新密码登录 */
    public function test_reset_password_takes_effect(): void
    {
        $target = User::create([
            'name' => 'wang', 'username' => 'wang', 'password' => 'old12345',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_USER,
        ]);

        $this->actingAs($this->root);
        $this->postJson('/api/admin/users/'.$target->id.'/password', ['password' => 'new98765'])
            ->assertOk()
            ->assertJsonPath('message', '密码已重置');

        $this->assertTrue(Hash::check('new98765', $target->fresh()->password));
        $this->assertFalse(Hash::check('old12345', $target->fresh()->password));

        // 太短的密码不接受
        $this->postJson('/api/admin/users/'.$target->id.'/password', ['password' => '123'])
            ->assertUnprocessable();
    }

    /** 总管理员指派与撤销机构管理员 */
    public function test_super_admin_assigns_and_revokes_org_admin(): void
    {
        $target = User::create([
            'name' => 'xiao', 'username' => 'xiao', 'password' => 'secret123',
            'organization_code' => 'swim_a', 'role' => User::ROLE_USER,
        ]);

        $this->actingAs($this->root);

        $this->postJson('/api/admin/users/'.$target->id.'/role', ['role' => User::ROLE_ORG_ADMIN])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_ORG_ADMIN);

        $this->postJson('/api/admin/users/'.$target->id.'/role', ['role' => User::ROLE_USER])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_USER);

        // 角色不能升级成总管理员
        $this->postJson('/api/admin/users/'.$target->id.'/role', ['role' => User::ROLE_ADMIN])
            ->assertUnprocessable();
    }

    /** 机构管理员不能指派角色，也不能碰别的机构的用户 */
    public function test_org_admin_cannot_assign_role_or_touch_other_org(): void
    {
        $outsider = User::create([
            'name' => 'out', 'username' => 'out', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_USER,
        ]);

        $this->actingAs($this->boss);

        $this->postJson('/api/admin/users/'.$outsider->id.'/role', ['role' => User::ROLE_ORG_ADMIN])
            ->assertForbidden();

        $this->putJson('/api/admin/users/'.$outsider->id, ['username' => 'hacked'])
            ->assertForbidden();

        $this->deleteJson('/api/admin/users/'.$outsider->id)->assertForbidden();

        $this->assertSame('out', $outsider->fresh()->username);
    }

    /** 总管理员账号不可删除、不可降级 */
    public function test_super_admin_account_is_protected(): void
    {
        $this->actingAs($this->root);

        $this->deleteJson('/api/admin/users/'.$this->root->id)->assertForbidden();
        $this->postJson('/api/admin/users/'.$this->root->id.'/role', ['role' => User::ROLE_USER])
            ->assertForbidden();

        $this->assertNotNull($this->root->fresh());
        $this->assertTrue($this->root->fresh()->isAdmin());
    }

    /** 不能删除自己 */
    public function test_cannot_delete_self(): void
    {
        $this->actingAs($this->boss);

        $this->deleteJson('/api/admin/users/'.$this->boss->id)->assertForbidden();
        $this->assertNotNull($this->boss->fresh());
    }

    /** 删除账号会顺带解绑教练档案 */
    public function test_deleting_user_unlinks_coach_profile(): void
    {
        $coach = \App\Models\Coach::create([
            'organization_code' => 'tennis_a', 'name' => '孟宇', 'active' => true,
        ]);

        $user = User::create([
            'name' => 'mengyu', 'username' => 'mengyu', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_USER,
        ]);

        app(\App\Services\CoachService::class)->linkUser($coach, $user);

        $this->assertSame($user->id, $coach->fresh()->user_id);

        $this->actingAs($this->root);
        $this->deleteJson('/api/admin/users/'.$user->id)->assertOk();

        $this->assertNull($coach->fresh()->user_id);
        $this->assertNull($user->fresh());
    }

    /** 用户列表按机构隔离，且支持关键词搜索 */
    public function test_user_list_is_scoped_and_searchable(): void
    {
        User::create([
            'name' => '阿蓝', 'username' => 'lanzi', 'password' => 'secret123',
            'organization_code' => 'swim_a', 'role' => User::ROLE_USER,
        ]);
        User::create([
            'name' => '小棋', 'username' => 'qizi', 'password' => 'secret123',
            'organization_code' => 'qi_yuan_a', 'role' => User::ROLE_USER,
        ]);

        $this->actingAs($this->root);

        $this->postJson('/api/admin/switch-organization', ['organization_code' => 'swim_a'])->assertOk();

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('users.total', 1)
            ->assertJsonPath('users.items.0.username', 'lanzi');

        $this->getJson('/api/admin/users?q='.urlencode('小棋'))
            ->assertOk()
            ->assertJsonPath('users.total', 0);
    }
}
