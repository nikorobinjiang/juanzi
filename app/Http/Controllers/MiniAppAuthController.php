<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\MiniAppAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiniAppAuthController extends Controller
{
    /** 机构下拉列表（公开） */
    public function organizations(): JsonResponse
    {
        return response()->json([
            'data' => Organization::query()
                ->orderBy('id')
                ->get(['code', 'name'])
                ->map(fn (Organization $organization): array => [
                    'code' => $organization->code,
                    'name' => $organization->name,
                ])
                ->all(),
        ]);
    }

    public function staffLogin(Request $request, MiniAppAuthService $service): JsonResponse
    {
        $data = $request->validate([
            'organization_code' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ], [
            'organization_code.required' => '请选择机构',
            'username.required' => '请输入用户名',
            'password.required' => '请输入密码',
        ]);

        return response()->json([
            'data' => $service->staffLogin($data['organization_code'], $data['username'], $data['password']),
        ]);
    }

    public function customerLogin(Request $request, MiniAppAuthService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'phone_code' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:50'],
        ], [
            'code.required' => '微信登录凭证缺失，请重试',
        ]);

        return response()->json([
            'data' => $service->customerLogin(
                $data['code'],
                $data['phone_code'] ?? null,
                $data['phone'] ?? null,
                $data['name'] ?? null,
            ),
        ]);
    }

    /** 当前 token 对应的身份 */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return response()->json([
                'data' => [
                    'role' => 'staff',
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'organization_code' => $user->organization_code,
                ],
            ]);
        }

        if ($user instanceof Customer) {
            return response()->json([
                'data' => [
                    'role' => 'customer',
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->maskedPhone(),
                ],
            ]);
        }

        return response()->json(['message' => '登录状态无效'], 401);
    }
}
