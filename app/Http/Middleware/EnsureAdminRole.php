<?php

namespace App\Http\Middleware;

use App\Support\AdminContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台准入：只有总管理员 / 机构管理员能过
 *
 * 机构隔离交给 AdminContext（机构管理员只能看到自己机构），
 * 这里只做「能不能进后台」这一层判断，避免每个控制器重复写角色判断。
 *
 * 接口请求返回 403 JSON（前端统一提示），页面请求跳回首页（无后台权限的普通账号不该看到错误页）。
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AdminContext $admin */
        $admin = app(AdminContext::class);

        if (! $admin->isManager()) {
            return $this->deny($request, '无后台管理权限');
        }

        return $next($request);
    }

    /**
     * 统一的拒绝响应
     */
    protected function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $message], 403);
        }

        return redirect('/');
    }
}
