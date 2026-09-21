<?php

namespace App\Http\Controllers;

use App\Services\AdminOrganizationService;
use App\Support\AdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 后台：机构与机构认证码
 *
 * 清单与切换仅总管理员可用（中间件 super.admin 已挡一层）；
 * 查看 / 重置认证码两类管理员都能用，但只能作用于自己可管理的机构。
 */
class AdminOrganizationController extends Controller
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminOrganizationService $organizations
    ) {}

    /**
     * 全部机构清单（仅总管理员）
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'organizations' => $this->organizations->list(),
            'current_code' => $this->admin->currentCode(),
        ]);
    }

    /**
     * 当前管理机构（含认证码）
     */
    public function show(): JsonResponse
    {
        $code = $this->admin->currentCode();
        $org = $this->organizations->detail($code);

        return $org
            ? response()->json(['organization' => $org, 'is_super_admin' => $this->admin->isSuperAdmin()])
            : response()->json(['error' => '机构不存在'], 404);
    }

    /**
     * 重置认证码（?org= 可指定机构，默认当前管理机构）
     */
    public function resetAuthCode(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('org', '')) ?: $this->admin->currentCode();

        $org = $this->organizations->resetAuthCode($code);

        return response()->json([
            'organization' => $org,
            'message' => '机构认证码已重置，旧码立即失效',
        ]);
    }

    /**
     * 切换总管理员当前管理的机构（仅总管理员）
     */
    public function switchOrganization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_code' => ['required', 'string'],
        ], [
            'organization_code.required' => '请选择机构',
        ]);

        if (! $this->admin->switchTo($validated['organization_code'])) {
            throw ValidationException::withMessages(['organization_code' => '无权管理该机构']);
        }

        return response()->json([
            'organization' => $this->admin->currentOrganization(),
            'message' => '已切换管理机构',
        ]);
    }
}
