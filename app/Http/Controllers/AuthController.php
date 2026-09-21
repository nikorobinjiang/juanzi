<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 注册 / 登录 / 登出（用户名+密码，开放注册，机构来自 organizations 表）
 */
class AuthController extends Controller
{
    /** “上次选择的机构” Cookie 名（下次打开登录页默认选中该机构） */
    private const LAST_ORG_COOKIE = 'last_org_code';

    /** 记住时长：1 年（单位分钟） */
    private const LAST_ORG_COOKIE_MINUTES = 60 * 24 * 365;

    /** 登录页（机构下拉列表来自 organizations 表，默认选中上次登录成功的机构） */
    public function showLogin(Request $request): View
    {
        $organizations = $this->organizationList();

        return view('login', [
            'organizations' => $organizations,
            'defaultOrganization' => $this->rememberedOrganization($request, $organizations),
        ]);
    }

    /** 注册页（机构下拉列表来自 organizations 表） */
    public function showRegister(): View
    {
        return view('register', [
            'organizations' => $this->organizationList(),
        ]);
    }

    /**
     * 机构初始化状态查询（注册页选择机构后 AJAX 调用）
     *
     * @return JsonResponse {initialized: bool, default_code: string|null}
     *                     未初始化机构返回系统临时生成的码（不落库，提交注册时才持久化）
     */
    public function organizationStatus(string $code): JsonResponse
    {
        $org = Organization::where('code', $code)->first();

        if (! $org) {
            return response()->json(['error' => '机构不存在'], 404);
        }

        return response()->json([
            'initialized' => $org->isInitialized(),
            'default_code' => $org->isInitialized() ? null : Organization::generateAuthCode(),
        ]);
    }

    /**
     * 注册：校验用户名唯一 / 密码一致性 / 机构认证码，创建后直接登录
     *
     * 认证码规则：
     * - 机构未初始化（auth_code 为 null）→ 首次注册，以用户提交的码初始化该机构认证码
     * - 机构已初始化 → 必须提交与库中一致的码（大小写不敏感，统一转大写比较）
     * 事务 + 行锁：并发首次注册同一机构时，仅一人写入，另一人走比对分支
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // 用户名在所选机构内唯一，不同机构允许重复
            'username' => ['required', 'string', 'min:2', 'max:50',
                Rule::unique('users', 'username')->where('organization_code', $request->input('organization_code'))],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'organization_code' => ['required', 'string', Rule::in($this->orgCodes())],
            'organization_auth_code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6}$/'],
        ], [
            'username.required' => '请输入用户名',
            'username.min' => '用户名至少 2 个字符',
            'username.max' => '用户名不能超过 50 个字符',
            'username.unique' => '该用户名已被注册，请换一个',
            'password.required' => '请输入密码',
            'password.min' => '密码至少 6 位',
            'password.confirmed' => '两次输入的密码不一致',
            'organization_code.required' => '请选择所属机构',
            'organization_code.in' => '请选择有效的机构',
            'organization_auth_code.required' => '请输入机构认证码',
            'organization_auth_code.regex' => '机构认证码需为 6 位字母或数字',
        ]);

        $submittedCode = strtoupper($validated['organization_auth_code']);

        $user = DB::transaction(function () use ($validated, $submittedCode): User {
            // 行锁防并发：两个用户同时首次注册同一机构时，仅一人能写入，另一人回退到比对分支
            $org = Organization::where('code', $validated['organization_code'])->lockForUpdate()->first();

            if (! $org) {
                throw ValidationException::withMessages(['organization_code' => '请选择有效的机构']);
            }

            if (! $org->isInitialized()) {
                // 首次注册该机构：以用户提交的码初始化认证码
                $org->update(['auth_code' => $submittedCode]);
            } elseif ($org->auth_code !== $submittedCode) {
                throw ValidationException::withMessages(['organization_auth_code' => '机构认证码不正确']);
            }

            return User::create([
                'name' => $validated['username'],
                'username' => $validated['username'],
                'password' => $validated['password'], // 模型 casts 会自动哈希
                'organization_code' => $validated['organization_code'],
                // 开放注册一律是普通用户；机构管理员由总管理员在后台指派
                'role' => User::ROLE_USER,
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        // 记住本次选择的机构，下次打开登录页默认选中
        $this->rememberOrganization($validated['organization_code']);

        return redirect('/');
    }

    /** 登录：机构 + 用户名 + 密码（用户名在机构内唯一，按机构+用户名精确匹配，跨机构同名互不干扰） */
    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_code' => ['required', 'string', Rule::in($this->orgCodes())],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'organization_code.required' => '请选择所属机构',
            'organization_code.in' => '请选择有效的机构',
            'username.required' => '请输入用户名',
            'password.required' => '请输入密码',
        ]);

        // 按机构+用户名定位用户（用户名机构内唯一），跨机构同名各自归属各自机构
        $user = User::where('username', $validated['username'])
            ->where('organization_code', $validated['organization_code'])
            ->first();
        if (! $user) {
            return back()
                ->withErrors(['username' => '用户名、密码或机构选择不正确'])
                ->onlyInput('username', 'organization_code');
        }

        if (Auth::attempt([
            'organization_code' => $validated['organization_code'],
            'username' => $validated['username'],
            'password' => $validated['password'],
        ], (bool) $request->boolean('remember'))) {
            $request->session()->regenerate();

            // 记住本次选择的机构，下次打开登录页默认选中
            $this->rememberOrganization($validated['organization_code']);

            return redirect()->intended('/');
        }

        return back()
            ->withErrors(['username' => '用户名或密码不正确'])
            ->onlyInput('username', 'organization_code');
    }

    /** 登出 */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /** 记住用户本次选择的机构（写 1 年 Cookie，登出后依然保留） */
    private function rememberOrganization(string $code): void
    {
        Cookie::queue(self::LAST_ORG_COOKIE, $code, self::LAST_ORG_COOKIE_MINUTES);
    }

    /**
     * 读取“上次选择的机构”code
     *
     * 无记录、或该机构已从 organizations 表移除时返回空字符串，
     * 让登录页下拉保持未选中状态（不预选任何机构）
     *
     * @param  array<int, array{code: string, name: string}>  $organizations
     */
    private function rememberedOrganization(Request $request, array $organizations): string
    {
        $code = (string) $request->cookie(self::LAST_ORG_COOKIE);

        if ($code === '') {
            return '';
        }

        foreach ($organizations as $org) {
            if ($org['code'] === $code) {
                return $code;
            }
        }

        return '';
    }

    /** 从 organizations 表提取机构 code 列表 */
    private function orgCodes(): array
    {
        return Organization::query()->pluck('code')->all();
    }

    /** 机构下拉数据（code/name），与旧 config 结构保持一致，视图零改动 */
    private function organizationList(): array
    {
        return Organization::query()
            ->orderBy('id')
            ->get(['code', 'name'])
            ->map(fn (Organization $org) => ['code' => $org->code, 'name' => $org->name])
            ->all();
    }
}
