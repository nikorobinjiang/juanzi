<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台机构认证码：查看、重置与生效验证
 */
class AdminOrganizationCodeTest extends TestCase
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

    /** 未初始化的机构：后台查看时不暴露认证码 */
    public function test_shows_current_organization_code(): void
    {
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'ABC234']);

        $this->actingAs($this->root);

        $this->getJson('/api/admin/organization')
            ->assertOk()
            ->assertJsonPath('organization.code', 'tennis_a')
            ->assertJsonPath('organization.auth_code', 'ABC234')
            ->assertJsonPath('organization.initialized', true);
    }

    /** 重置后认证码变化，且新码可用于注册、旧码失效 */
    public function test_reset_auth_code_generates_new_code(): void
    {
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'OLD123']);

        $this->actingAs($this->root);

        $new = $this->postJson('/api/admin/organization/reset-auth-code')
            ->assertOk()
            ->assertJsonPath('organization.code', 'tennis_a')
            ->json('organization.auth_code');

        $this->assertNotSame('OLD123', $new);
        $this->assertSame(6, strlen($new));
        $this->assertSame($new, Organization::where('code', 'tennis_a')->value('auth_code'));

        // 注册页是 guest 专用，先登出再验证新旧码
        $this->post('/logout');

        $this->post('/register', [
            'username' => 'newbie',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => $new,
        ])->assertRedirect('/');

        $this->assertDatabaseHas('users', ['username' => 'newbie', 'organization_code' => 'tennis_a']);

        // 注册成功会自动登录，先登出再试旧码
        $this->post('/logout');

        $this->post('/register', [
            'username' => 'stale',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'OLD123',
        ])->assertSessionHasErrors('organization_auth_code');
    }

    /** 机构管理员只能重置自己机构的认证码 */
    public function test_org_admin_can_only_reset_own_organization_code(): void
    {
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'AAA111']);

        $this->actingAs($this->boss);

        $this->postJson('/api/admin/organization/reset-auth-code', ['org' => 'tennis_a'])->assertForbidden();
        $this->assertSame('AAA111', Organization::where('code', 'tennis_a')->value('auth_code'));

        $this->postJson('/api/admin/organization/reset-auth-code', ['org' => 'alan_tennis'])->assertOk();
        $this->assertNotNull(Organization::where('code', 'alan_tennis')->value('auth_code'));
    }

    /** 总管理员可指定任意机构重置 */
    public function test_super_admin_can_reset_any_organization_code(): void
    {
        $this->actingAs($this->root);

        $this->postJson('/api/admin/organization/reset-auth-code', ['org' => 'qi_yuan_a'])
            ->assertOk()
            ->assertJsonPath('organization.code', 'qi_yuan_a');

        $this->assertNotNull(Organization::where('code', 'qi_yuan_a')->value('auth_code'));

        // 不存在的机构直接拒绝
        $this->postJson('/api/admin/organization/reset-auth-code', ['org' => 'ghost'])->assertForbidden();
    }

    /** 机构清单（仅总管理员）带账号数 */
    public function test_organization_list_includes_all_orgs(): void
    {
        $this->actingAs($this->root);

        $response = $this->getJson('/api/admin/organizations')->assertOk();

        $this->assertCount(\App\Models\Organization::query()->count(), $response->json('organizations'));
        $this->assertSame('tennis_a', $response->json('organizations.0.code'));
    }
}
