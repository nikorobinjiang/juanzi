<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMiniappStaff
{
    /**
     * 机构人员接口：必须是 User 且 token 具备 staff ability。
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->tokenCan('staff')) {
            return response()->json(['message' => '需要机构人员权限'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
