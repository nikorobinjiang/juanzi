<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 注册 / 登录 / 登出（用户名+密码，开放注册，机构来自配置文件）
 */
class AuthController extends Controller
{
    /** 登录页（机构下拉列表来自 config/organizations.php） */
    public function showLogin(): View
    {
        return view('login', [
            'organizations' => config('organizations.list', []),
        ]);
    }

    /** 注册页（机构下拉列表来自 config/organizations.php） */
    public function showRegister(): View
    {
        return view('register', [
            'organizations' => config('organizations.list', []),
        ]);
    }

    /** 注册：校验用户名唯一 / 密码一致性 / 机构在配置内，创建后直接登录 */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:2', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'organization_code' => ['required', 'string', Rule::in($this->orgCodes())],
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
        ]);

        $user = User::create([
            'name' => $validated['username'],
            'username' => $validated['username'],
            'password' => $validated['password'], // 模型 casts 会自动哈希
            'organization_code' => $validated['organization_code'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/appoints');
    }

    /** 登录：机构 + 用户名 + 密码（用户名全局唯一，机构须与注册时一致） */
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

        // 用户名全局唯一：先按用户名查用户，校验所选机构与注册时所属机构一致，防止选错机构进错工作区
        $user = User::where('username', $validated['username'])->first();
        if (! $user || $user->organization_code !== $validated['organization_code']) {
            return back()
                ->withErrors(['username' => '用户名、密码或机构选择不正确'])
                ->onlyInput('username', 'organization_code');
        }

        if (Auth::attempt([
            'username' => $validated['username'],
            'password' => $validated['password'],
        ], (bool) $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended('/appoints');
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

    /** 从配置提取机构 code 列表 */
    private function orgCodes(): array
    {
        return array_column(config('organizations.list', []), 'code');
    }
}
