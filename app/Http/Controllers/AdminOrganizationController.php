<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AdminOrganizationService;
use App\Support\AdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
     * 切换机构场地模式（?org= 可指定机构，默认当前管理机构）
     *
     * 两类管理员都能改，但只能改自己可管理的机构（越权由服务层挡）。
     */
    public function venueMode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_mode' => ['required', 'string', Rule::in(Organization::VENUE_MODES)],
        ], [
            'venue_mode.required' => '请选择场地模式',
            'venue_mode.in' => '场地模式不合法',
        ]);

        $code = trim((string) $request->input('org', '')) ?: $this->admin->currentCode();

        $org = $this->organizations->setVenueMode($code, $validated['venue_mode']);

        return response()->json([
            'organization' => $org,
            'message' => ($org['venue_mode'] ?? '') === Organization::VENUE_MODE_NONE
                ? '已切换为「不按场地排课」：约课不再分配场地，也不做场地冲突检测'
                : '已切换为「按场地排课」：约课需分配场地并检测场地冲突',
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
