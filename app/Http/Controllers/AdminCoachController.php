<?php

namespace App\Http\Controllers;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\CoachService;
use App\Support\AdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 后台：教练档案接口
 *
 * 建档 / 改名 / 停用 / 绑定账号全部复用 CoachService，保证与约课、固定场、AI 名单同一口径：
 * 改名会同步三张业务表，停用后不再进 AI 名单，绑定账号走同一套占用校验。
 */
class AdminCoachController extends Controller
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly CoachService $coaches
    ) {}

    /**
     * 教练列表（含停用）
     */
    public function index(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        $coaches = $this->coaches->listForAdmin($code);

        return response()->json([
            'organization_code' => $code,
            'coaches' => $coaches->map(fn (Coach $coach) => $this->coaches->presentForAdmin($coach))->all(),
        ]);
    }

    /**
     * 新建教练档案
     */
    public function store(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'aliases' => ['nullable'],
            'active' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => '请输入教练姓名',
            'name.max' => '教练姓名不能超过 50 个字符',
        ]);

        $coach = $this->coaches->createForAdmin($validated, $code);

        return response()->json(['coach' => $this->coaches->presentForAdmin($coach)], 201);
    }

    /**
     * 编辑教练档案（改名会同步业务表）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $coach = $this->findCoach($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'aliases' => ['nullable'],
            'active' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => '请输入教练姓名',
        ]);

        $coach = $this->coaches->updateForAdmin($coach, $validated);

        return response()->json(['coach' => $this->coaches->presentForAdmin($coach)]);
    }

    /**
     * 停用 / 启用
     */
    public function setActive(Request $request, int $id): JsonResponse
    {
        $coach = $this->findCoach($id);

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ], [
            'active.required' => '请选择在职状态',
        ]);

        $coach = $this->coaches->setActive($coach, (bool) $validated['active']);

        return response()->json([
            'coach' => $this->coaches->presentForAdmin($coach),
            'message' => $coach->active ? '已启用' : '已停用',
        ]);
    }

    /**
     * 删除教练（?force=1 表示明知有业务引用仍要删除）
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $coach = $this->findCoach($id);

        $this->coaches->deleteForAdmin($coach, $request->boolean('force'));

        return response()->json(['message' => '教练档案已删除']);
    }

    /**
     * 绑定登录账号（传 username 或 user_id）
     */
    public function linkUser(Request $request, int $id): JsonResponse
    {
        $coach = $this->findCoach($id);

        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        $key = trim((string) ($validated['username'] ?? ''));
        $user = null;

        if ($key !== '') {
            $user = $this->coaches->userByKey($key, (string) $coach->organization_code);
        } elseif (! empty($validated['user_id'])) {
            /** @var User|null $user */
            $user = User::query()->find((int) $validated['user_id']);
        }

        if (! $user) {
            throw ValidationException::withMessages(['username' => '未找到该账号（需与教练同机构）']);
        }

        $result = $this->coaches->linkUser($coach, $user, (bool) ($validated['force'] ?? false));

        if (! in_array($result['status'], [CoachService::LINK_LINKED, CoachService::LINK_ALREADY], true)) {
            return response()->json([
                'error' => $result['message'],
                'status' => $result['status'],
            ], 422);
        }

        return response()->json([
            'coach' => $this->coaches->presentForAdmin($result['coach']),
            'message' => $result['message'],
        ]);
    }

    /**
     * 解绑登录账号
     */
    public function unlinkUser(int $id): JsonResponse
    {
        $coach = $this->findCoach($id);

        $result = $this->coaches->unlinkUser($coach);

        if (! in_array($result['status'], [CoachService::LINK_UNLINKED, CoachService::LINK_ALREADY], true)) {
            return response()->json(['error' => $result['message'], 'status' => $result['status']], 422);
        }

        return response()->json([
            'coach' => $this->coaches->presentForAdmin($result['coach']),
            'message' => $result['message'],
        ]);
    }

    /**
     * 目标机构：默认后台当前机构，总管理员可用 ?org= 指定
     */
    private function orgCode(Request $request): string
    {
        $wanted = trim((string) $request->input('org', ''));

        if ($wanted !== '' && ! $this->admin->canManage($wanted)) {
            throw ValidationException::withMessages(['org' => '无权管理该机构']);
        }

        return $wanted !== '' ? $wanted : $this->admin->currentCode();
    }

    /**
     * 按主键取教练档案（脱离机构 Scope，跨机构管理时也能取到）
     */
    private function findCoach(int $id): Coach
    {
        /** @var Coach|null $coach */
        $coach = Coach::withoutGlobalScope(OrganizationScope::class)->find($id);

        if (! $coach) {
            throw new NotFoundHttpException('教练不存在');
        }

        if (! $this->admin->canManage((string) $coach->organization_code)) {
            throw ValidationException::withMessages(['coach' => '无权管理该教练']);
        }

        return $coach;
    }
}
