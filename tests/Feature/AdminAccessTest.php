<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台入口的可见性与越权拦截
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $username, string $role, string $org = 'tennis_a'): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
            'role' => $role,
        ]);
    }

    /** 普通用户进不了后台页面，接口也是 403 */
    public function test_normal_user_cannot_open_admin(): void
    {
        $this->actingAs($this->makeUser('nobody', User::ROLE_USER));

        $this->get('/admin')->assertRedirect('/');
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    /** 首页入口卡片只对管理员显示 */
    public function test_home_entry_only_for_managers(): void
    {
        $this->actingAs($this->makeUser('plain', User::ROLE_USER));
        $this->get('/')->assertDontSee('后台管理');

        $this->actingAs($this->makeUser('boss', User::ROLE_ORG_ADMIN));
        $this->get('/')->assertSee('后台管理');

        $this->actingAs($this->makeUser('root', User::ROLE_ADMIN));
        $this->get('/')->assertSee('后台管理');
    }

    /** 机构管理员能进后台，但看不到跨机构能力 */
    public function test_org_admin_can_open_admin_but_not_cross_org(): void
    {
        $this->actingAs($this->makeUser('boss', User::ROLE_ORG_ADMIN, 'tennis_a'));

        $this->get('/admin')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/organization')->assertOk();

        // 机构清单与切换是总管理员专属
        $this->getJson('/api/admin/organizations')->assertForbidden();
        $this->postJson('/api/admin/switch-organization', ['organization_code' => 'swim_a'])->assertForbidden();
    }

    /** 总管理员可查看全部机构并切换当前管理机构 */
    public function test_super_admin_can_switch_managed_organization(): void
    {
        $this->actingAs($this->makeUser('root', User::ROLE_ADMIN, 'tennis_a'));

        $this->getJson('/api/admin/organizations')
            ->assertOk()
            ->assertJsonPath('current_code', 'tennis_a');

        $this->postJson('/api/admin/switch-organization', ['organization_code' => 'swim_a'])
            ->assertOk()
            ->assertJsonPath('organization.code', 'swim_a');

        // 切换后列表查的是新机构
        $swimUser = $this->makeUser('swimmer', User::ROLE_USER, 'swim_a');

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('organization_code', 'swim_a')
            ->assertJsonPath('users.total', 1);

        // 切到一个不存在的机构会被拒
        $this->postJson('/api/admin/switch-organization', ['organization_code' => 'ghost'])->assertUnprocessable();
    }

    /** AdminSeeder 幂等创建总管理员，重复执行不报错也不重置角色 */
    public function test_admin_seeder_creates_super_admin_idempotently(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = User::where('username', 'admin')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        $password = $admin->password;
        $this->seed(AdminSeeder::class);

        $this->assertSame($password, $admin->fresh()->password);
        $this->assertSame(1, User::where('username', 'admin')->count());
    }

    /** 角色字段兜底：未知角色值不能获得后台权限 */
    public function test_unknown_role_is_treated_as_normal_user(): void
    {
        $user = $this->makeUser('weird', 'super_user');

        $this->assertFalse($user->fresh()->isManager());

        $this->actingAs($user->fresh());
        $this->getJson('/api/admin/users')->assertForbidden();
    }
}
