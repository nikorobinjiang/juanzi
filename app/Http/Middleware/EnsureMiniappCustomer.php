<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMiniappCustomer
{
    /**
     * 客户接口：必须是 Customer 且 token 具备 customer ability。
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Customer || ! $user->tokenCan('customer')) {
            return response()->json(['message' => '请先使用微信登录'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
