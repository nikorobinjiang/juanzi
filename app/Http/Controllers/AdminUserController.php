<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminUserService;
use App\Support\AdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 后台：用户管理接口
 *
 * 机构范围：总管理员可用 ?org= 指定任意机构（或跟随后台当前机构），
 * 机构管理员传什么都只能看自己机构（由 AdminContext / 服务层兜底）。
 */
class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminUserService $users
    ) {}

    /**
     * 用户列表：?org=机构&q=关键词&page=1&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $orgCode = $this->resolveOrgCode($request);
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(5, min($perPage, 100));

        return response()->json([
            'organization_code' => $orgCode,
            'is_super_admin' => $this->admin->isSuperAdmin(),
            'users' => $this->users->users(
                $orgCode,
                mb_substr(trim((string) $request->input('q', '')), 0, 50),
                $perPage
            ),
        ]);
    }

    /**
     * 新建账号
     */
    public function store(Request $request): JsonResponse
    {
        $orgCode = $this->resolveOrgCode($request, true);

        $validated = $request->validate([
            'username' => ['required', 'string', 'min:2', 'max:50',
                Rule::unique('users', 'username')->where('organization_code', $orgCode)],
            'name' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6'],
            // 总管理员只能建普通用户或机构管理员；再高的权限走 seeder，避免接口提权
            'role' => ['nullable', Rule::in([User::ROLE_USER, User::ROLE_ORG_ADMIN])],
        ], [
            'username.required' => '请输入登录名',
            'username.min' => '登录名至少 2 个字符',
            'username.max' => '登录名不能超过 50 个字符',
            'username.unique' => '该登录名在本机构已存在',
            'password.required' => '请输入初始密码',
            'password.min' => '密码至少 6 位',
        ]);

        $user = $this->users->create($validated, $orgCode);

        return response()->json(['user' => $this->users->present($user)], 201);
    }

    /**
     * 编辑资料（昵称 / 登录名）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->findUser($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:50'],
            'username' => ['required', 'string', 'min:2', 'max:50',
                Rule::unique('users', 'username')
                    ->where('organization_code', $user->organization_code)
                    ->ignore($user->getKey())],
        ], [
            'username.required' => '请输入登录名',
            'username.min' => '登录名至少 2 个字符',
            'username.unique' => '该登录名在本机构已存在',
        ]);

        $user = $this->users->update($user, $validated);

        return response()->json(['user' => $this->users->present($user)]);
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = $this->findUser($id);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ], [
            'password.required' => '请输入新密码',
            'password.min' => '密码至少 6 位',
        ]);

        $this->users->resetPassword($user, $validated['password']);

        return response()->json(['message' => '密码已重置']);
    }

    /**
     * 指派 / 撤销机构管理员（仅总管理员）
     */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $user = $this->findUser($id);

        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ORG_ADMIN])],
        ], [
            'role.required' => '请选择角色',
            'role.in' => '角色只能是普通用户或机构管理员',
        ]);

        $user = $this->users->assignRole($user, $validated['role']);

        return response()->json(['user' => $this->users->present($user)]);
    }

    /**
     * 删除账号
     */
    public function destroy(int $id): JsonResponse
    {
        $user = $this->findUser($id);

        $this->users->delete($user);

        return response()->json(['message' => '账号已删除']);
    }

    /**
     * 取目标机构：?org= 越权或缺失时回落到后台当前机构
     */
    private function resolveOrgCode(Request $request, bool $allowExplicit = false): string
    {
        $wanted = trim((string) $request->input('org', ''));

        if ($wanted !== '' && $this->admin->canManage($wanted)) {
            return $wanted;
        }

        // 显式传了但不可管理：直接报错，避免"悄悄查了自己机构"造成误解
        if ($allowExplicit && $wanted !== '') {
            throw ValidationException::withMessages(['org' => '无权管理该机构']);
        }

        return $this->admin->currentCode();
    }

    /**
     * 按主键取用户（删掉了就是 404）
     */
    private function findUser(int $id): User
    {
        /** @var User|null $user */
        $user = User::query()->find($id);

        if (! $user) {
            throw new NotFoundHttpException('用户不存在');
        }

        return $user;
    }
}
