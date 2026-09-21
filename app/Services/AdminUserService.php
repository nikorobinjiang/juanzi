<?php

namespace App\Services;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\AdminContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 后台：用户管理（列表 / 新建 / 编辑 / 重置密码 / 删除 / 角色指派）
 *
 * 权限口径（服务层再兜一次底，防接口直调绕过中间件）：
 * - 机构管理员：只能动自己机构内的普通用户与机构管理员，碰不到总管理员，也不能指派角色
 * - 总管理员：可动所有机构的用户，但总管理员账号本身不可删除、不可降级
 * - 任何人都不能删除自己（避免把自己锁在门外）
 *
 * 列表查询显式带 organization_code 并脱离全局 Scope：总管理员管理的是「别人家」的数据，
 * OrganizationScope 只会按登录者自身机构过滤，那样会查不到目标机构的用户。
 */
class AdminUserService
{
    public function __construct(private readonly AdminContext $admin) {}

    /**
     * 机构内用户列表（带搜索与分页）
     *
     * 教练绑定状态用 with('coach') 预加载，避免列表逐条回查。
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function users(string $orgCode, string $keyword = '', int $perPage = 20): array
    {
        $this->assertManageable($orgCode);

        $query = User::query()
            ->with('coach:id,name,organization_code')
            ->where('organization_code', $orgCode);

        $keyword = trim($keyword);

        if ($keyword !== '') {
            $query->where(fn ($q) => $q
                ->where('username', 'like', '%'.$keyword.'%')
                ->orWhere('name', 'like', '%'.$keyword.'%'));
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->orderByDesc('id')->paginate($perPage);

        return [
            'items' => $page->getCollection()->map(fn (User $user) => $this->present($user))->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * 新建账号
     *
     * @param  array<string, mixed>  $data  已校验：username / name / password / role
     */
    public function create(array $data, string $orgCode): User
    {
        $this->assertManageable($orgCode);

        $role = $this->normalizedRole($data['role'] ?? User::ROLE_USER);

        // 只有总管理员能建管理员账号，其余一律落成普通用户
        if ($role !== User::ROLE_USER && ! $this->admin->isSuperAdmin()) {
            $role = User::ROLE_USER;
        }

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['name'] ?? $data['username'],
            'password' => $data['password'], // 模型 casts 自动哈希
            'organization_code' => $orgCode,
            'role' => $role,
        ]);

        Log::info('后台新建账号', [
            'operator_id' => $this->admin->user()?->getKey(),
            'organization_code' => $orgCode,
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * 编辑资料（昵称 / 登录名）
     *
     * @param  array<string, mixed>  $data  name / username
     */
    public function update(User $user, array $data): User
    {
        $this->assertOperable($user);

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'username' => $data['username'] ?? $user->username,
        ])->save();

        Log::info('后台编辑账号', [
            'operator_id' => $this->admin->user()?->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return $user;
    }

    /**
     * 重置密码（管理员直接设新密码，不走邮件令牌）
     */
    public function resetPassword(User $user, string $password): void
    {
        $this->assertOperable($user);

        $user->password = $password; // 模型 casts 自动哈希
        $user->save();

        Log::info('后台重置密码', [
            'operator_id' => $this->admin->user()?->getKey(),
            'user_id' => $user->getKey(),
            'organization_code' => $user->organization_code,
        ]);
    }

    /**
     * 指派 / 撤销机构管理员（仅总管理员）
     *
     * 总管理员账号不可降级：系统至少要留一个能进后台的人。
     */
    public function assignRole(User $user, string $role): User
    {
        if (! $this->admin->isSuperAdmin()) {
            throw new AccessDeniedHttpException('仅总管理员可指派角色');
        }

        $this->assertOperable($user);

        $role = $this->normalizedRole($role);

        if ($user->isAdmin()) {
            throw new AccessDeniedHttpException('总管理员账号不可变更角色');
        }

        $user->role = $role;
        $user->save();

        Log::info('后台指派角色', [
            'operator_id' => $this->admin->user()?->getKey(),
            'user_id' => $user->getKey(),
            'organization_code' => $user->organization_code,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * 删除账号
     *
     * 总管理员不可删、自己不可删；教练档案的 user_id 一并置空（coaches.user_id 唯一，
     * 留着会挡住该账号后续被重建或绑给别人）。
     */
    public function delete(User $user): void
    {
        $this->assertOperable($user);

        if ($user->isAdmin()) {
            throw new AccessDeniedHttpException('总管理员账号不可删除');
        }

        $operator = $this->admin->user();

        if ($operator && (int) $operator->getKey() === (int) $user->getKey()) {
            throw new AccessDeniedHttpException('不能删除当前登录的账号');
        }

        $id = $user->getKey();
        $orgCode = (string) $user->organization_code;

        Coach::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $id)
            ->update(['user_id' => null, 'updated_at' => now()]);

        // 教练档案有请求内缓存，解绑后要失效，否则列表里还显示旧账号
        app(CoachService::class)->flush($orgCode);

        $user->delete();

        Log::info('后台删除账号', [
            'operator_id' => $operator?->getKey(),
            'user_id' => $id,
            'organization_code' => $orgCode,
        ]);
    }

    /**
     * 列表展示结构（不暴露任何凭据字段）
     *
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->currentRole(),
            'role_label' => $user->role_label,
            'organization_code' => $user->organization_code,
            'coach_name' => $user->coach?->name,
            'created_at' => $user->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * 角色值归一：未知值一律按普通用户处理
     */
    private function normalizedRole(mixed $role): string
    {
        $role = (string) $role;

        return in_array($role, User::ROLES, true) ? $role : User::ROLE_USER;
    }

    /**
     * 目标机构必须在可管理范围内
     */
    private function assertManageable(string $orgCode): void
    {
        if (! $this->admin->canManage($orgCode)) {
            throw new AccessDeniedHttpException('无权管理该机构');
        }
    }

    /**
     * 目标账号是否可操作
     */
    private function assertOperable(User $user): void
    {
        $this->assertManageable((string) $user->organization_code);

        // 机构管理员碰不到总管理员（总管理员可能不属于本机构，这里再挡一次）
        if ($user->isAdmin() && ! $this->admin->isSuperAdmin()) {
            throw new AccessDeniedHttpException('无权操作总管理员账号');
        }
    }
}
