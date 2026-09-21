<?php

namespace App\Http\Middleware;

use App\Support\AdminContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅限总管理员：跨机构能力（机构列表、切换机构、指派机构管理员等）
 *
 * 机构管理员被挡在这里，防止通过直接调接口拿到别的机构数据。
 */
class EnsureSuperAdminRole extends EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AdminContext $admin */
        $admin = app(AdminContext::class);

        if (! $admin->isSuperAdmin()) {
            return $this->deny($request, '仅总管理员可操作');
        }

        return $next($request);
    }
}
