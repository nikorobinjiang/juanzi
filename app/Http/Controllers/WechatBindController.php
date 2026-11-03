<?php

namespace App\Http\Controllers;

use App\Models\Coach;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\WechatBinding;
use App\Services\CoachService;
use App\Services\LessonReminderService;
use App\Services\WechatService;
use App\Support\BookingWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

/**
 * 微信绑定页（免登录）
 *
 * 学员 / 教练在微信里打开同一张页面完成身份绑定，两条路径：
 * 1. 手机号优先：命中档案即绑定（同一手机号多个孩子时一次性全部绑定）
 * 2. 手动匹配：后台没登记手机号的，按「机构 → 姓名」两级下拉认领
 *
 * 页面本身没有登录态，唯一的凭据是微信网页授权拿到的 openid：
 * 不带 code 直接访问会被重定向走授权流程，爬虫拿不到名单；名单接口另外做了单 openid 节流。
 */
class WechatBindController extends Controller
{
    /** session key：网页授权重试次数，用于防止授权失败时无限跳转 */
    private const OCR_ATTEMPT_KEY = 'wechat_oauth_attempts';

    /* -----------------------------------------------------------------
     | 页面
     | ----------------------------------------------------------------- */

    /**
     * 绑定页：授权回填 openid → 展示绑定状态与明日课程
     */
    public function show(Request $request, WechatService $wechat, LessonReminderService $reminders): View|RedirectResponse
    {
        $role = $this->role($request);
        $openid = $this->openid($request, $wechat);

        // 还没拿到 openid：先把用户送去微信授权，授权完带着 code 回到本页
        if ($openid === null) {
            return $this->redirectToOAuth($request, $wechat, $reminders, $role);
        }

        return view('wechat-bind', [
            'role' => $role,
            'organizations' => $this->organizationList(),
            'bindings' => $this->bindingsFor($openid, $role),
            'organizationNames' => $this->organizationNames(),
            'tomorrow' => $this->tomorrowLessons($reminders, $openid, $role),
            'tomorrowLabel' => $this->tomorrowLabel(),
            'configured' => $wechat->configured(),
            'pageError' => '',
        ]);
    }

    /* -----------------------------------------------------------------
     | 绑定 / 解绑
     | ----------------------------------------------------------------- */

    /**
     * 提交绑定：手机号路径与手动选择路径二选一
     */
    public function submit(Request $request): RedirectResponse
    {
        $role = $this->role($request);
        $openid = $this->openid($request, app(WechatService::class));

        if ($openid === null) {
            return redirect()->route('wechat.bind', ['role' => $role])
                ->withErrors(['wechat' => '微信授权已失效，请重新打开链接']);
        }

        if ($this->throttled($openid)) {
            return back()->withErrors(['wechat' => '操作过于频繁，请稍后再试']);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in([WechatBinding::TYPE_STUDENT, WechatBinding::TYPE_COACH])],
            // 手机号路径可以不选机构（自动归属）；手动路径必须选，否则跨机构同名会误绑
            'organization_code' => ['nullable', 'string', Rule::in($this->organizationCodes())],
            'phone' => ['nullable', 'string', 'max:20'],
            'person' => ['nullable', 'string', 'max:50'],
        ], [
            'organization_code.in' => '请选择有效的机构',
        ]);

        $phone = $this->normalizePhone((string) ($validated['phone'] ?? ''));
        $person = trim((string) ($validated['person'] ?? ''));
        $orgCode = trim((string) ($validated['organization_code'] ?? ''));

        // 两条路径互斥：给了手机号就忽略手动选择，避免用户以为把两个身份都绑了
        if ($phone !== '') {
            $result = $this->bindByPhone($openid, $role, $orgCode, $phone);

            if ($result['count'] > 0) {
                return back()->with('status', sprintf('绑定成功，已关联 %d 个%s档案', $result['count'], $this->roleLabel($role)));
            }

            return back()
                ->withInput($request->only('phone'))
                ->withErrors(['phone' => $result['message']]);
        }

        if ($person !== '') {
            if ($orgCode === '') {
                return back()->withErrors(['person' => '请先在上方选择所属机构']);
            }

            $bound = $this->bindByPerson($openid, $role, $orgCode, $person);

            return $bound > 0
                ? back()->with('status', '绑定成功')
                : back()->withInput($request->only('person', 'organization_code'))
                    ->withErrors(['person' => '没有找到该档案，请确认机构与姓名是否一致']);
        }

        return back()->withErrors(['phone' => '请输入手机号，或手动选择姓名']);
    }

    /**
     * 解绑：只能解自己微信上的绑定
     */
    public function unbind(Request $request): RedirectResponse
    {
        $role = $this->role($request);
        $openid = $this->openid($request, app(WechatService::class));

        if ($openid === null) {
            return redirect()->route('wechat.bind', ['role' => $role]);
        }

        $validated = $request->validate([
            'binding_id' => ['required', 'integer'],
        ]);

        WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('id', (int) $validated['binding_id'])
            ->where('openid', $openid)
            ->delete();

        return back()->with('status', '已解除绑定');
    }

    /* -----------------------------------------------------------------
     | 名单接口
     | ----------------------------------------------------------------- */

    /**
     * 手动匹配用的姓名下拉数据源（按机构级联）
     */
    public function names(Request $request): JsonResponse
    {
        $openid = $this->openid($request, app(WechatService::class));

        // 名单属于敏感数据：没有 openid 或超频一律挡掉
        if ($openid === null || $this->throttled($openid)) {
            return response()->json(['names' => []], $openid === null ? 401 : 429);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in([WechatBinding::TYPE_STUDENT, WechatBinding::TYPE_COACH])],
            'organization_code' => ['required', 'string', Rule::in($this->organizationCodes())],
        ]);

        $orgCode = (string) $validated['organization_code'];

        $names = $validated['role'] === WechatBinding::TYPE_COACH
            ? app(CoachService::class)->activeNames($orgCode)
            : Student::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $orgCode)
                ->orderBy('name')
                ->pluck('name')
                ->all();

        return response()->json(['names' => array_values($names)]);
    }

    /* -----------------------------------------------------------------
     | 绑定写入
     | ----------------------------------------------------------------- */

    /**
     * 手机号路径：同一手机号命中多个学员时全部绑定（家长用一个号码带两个孩子）
     *
     * @return array{count: int, message: string}
     */
    private function bindByPhone(string $openid, string $role, string $orgCode, string $phone): array
    {
        $count = 0;

        if ($role === WechatBinding::TYPE_COACH) {
            $coaches = Coach::withoutGlobalScope(OrganizationScope::class)
                ->when($orgCode !== '', fn ($query) => $query->where('organization_code', $orgCode))
                ->where('phone', $phone)
                ->get();

            // 同名同号在不同机构重复时先让用户选机构，不允许一次猜两家
            if ($orgCode === '' && $coaches->pluck('organization_code')->unique()->count() > 1) {
                return ['count' => 0, 'message' => '这个手机号在多家机构都存在，请选择所属机构后重试'];
            }

            foreach ($coaches as $coach) {
                $count += $this->save($openid, $role, (string) $coach->organization_code, (int) $coach->id, $coach->name) ? 1 : 0;
            }

            return ['count' => $count, 'message' => '这个手机号没有匹配的教练档案，请用下方的手动选择'];
        }

        $students = Student::withoutGlobalScope(OrganizationScope::class)
            ->when($orgCode !== '', fn ($query) => $query->where('organization_code', $orgCode))
            ->where('phone', $phone)
            ->orderBy('name')
            ->get();

        if ($orgCode === '' && $students->pluck('organization_code')->unique()->count() > 1) {
            return ['count' => 0, 'message' => '这个手机号在多家机构都存在，请选择所属机构后重试'];
        }

        foreach ($students as $student) {
            $count += $this->save($openid, $role, (string) $student->organization_code, (int) $student->id, $student->name) ? 1 : 0;
        }

        return ['count' => $count, 'message' => '这个手机号没有匹配的学员档案，请用下方的手动选择'];
    }

    /**
     * 手动路径：按姓名认领，教练名走 CoachService 的别名归一
     */
    private function bindByPerson(string $openid, string $role, string $orgCode, string $person): int
    {
        if ($role === WechatBinding::TYPE_COACH) {
            // 教练档案按规范名存储，这里先归一再比对，防止「王教练」这类写法匹配不上
            $canonical = app(CoachService::class)->resolveCoachName($person, $orgCode);
            $coach = Coach::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $orgCode)
                ->where('name', $canonical)
                ->first();

            return $coach === null ? 0 : ($this->save($openid, $role, $orgCode, (int) $coach->id, $coach->name) ? 1 : 0);
        }

        // 学员名存在历史脏数据（"小明" vs "小明妈妈留的号"），精确优先，包含兜底
        $student = Student::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('name', $person)
            ->first()
            ?? Student::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $orgCode)
                ->where('name', 'like', '%'.$person.'%')
                ->orderBy('name')
                ->first();

        return $student === null ? 0 : ($this->save($openid, $role, $orgCode, (int) $student->id, $student->name) ? 1 : 0);
    }

    /**
     * 写入/更新绑定：同一微信重复绑定同一身份时刷新，不产生重复行
     */
    private function save(string $openid, string $role, string $orgCode, int $targetId, string $name): bool
    {
        $attributes = [
            'target_type' => $role,
            'source' => WechatBinding::SOURCE_MANUAL,
            'enabled' => true,
            'bound_at' => BookingWindow::now(),
        ];

        if ($role === WechatBinding::TYPE_COACH) {
            $attributes['coach_name'] = $name;
        } else {
            $attributes['student_name'] = $name;
        }

        WechatBinding::withoutGlobalScope(OrganizationScope::class)->updateOrCreate(
            [
                'organization_code' => $orgCode,
                'openid' => $openid,
                'target_key' => WechatBinding::targetKey($role, $targetId),
            ],
            $attributes
        );

        return true;
    }

    /* -----------------------------------------------------------------
     | 辅助
     | ----------------------------------------------------------------- */

    /**
     * 从 session 取 openid，没有就用 code 换；都不是返回 null
     */
    private function openid(Request $request, WechatService $wechat): ?string
    {
        $key = (string) config('wechat.oauth.session_key', 'wechat_openid');
        $existing = trim((string) $request->session()->get($key, ''));

        if ($existing !== '') {
            return $existing;
        }

        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return null;
        }

        $openid = $wechat->openidFromCode($code);

        if ($openid !== null) {
            $request->session()->put($key, $openid);
            $request->session()->forget(self::OCR_ATTEMPT_KEY);
        }

        return $openid;
    }

    /**
     * 跳转微信授权
     *
     * 未接线或授权反复失败时直接渲染带错误提示的页面，而不是继续跳，
     * 否则用户会在「授权 → 回来拿不到 openid → 再授权」之间无限打转。
     */
    private function redirectToOAuth(Request $request, WechatService $wechat, LessonReminderService $reminders, string $role): View|RedirectResponse
    {
        $attempts = (int) $request->session()->get(self::OCR_ATTEMPT_KEY, 0);

        if (! $wechat->configured()) {
            return $this->render($wechat, $reminders, $role, '尚未配置微信服务号，请联系机构管理员');
        }

        if ($attempts >= 2) {
            $request->session()->forget(self::OCR_ATTEMPT_KEY);

            return $this->render($wechat, $reminders, $role, '微信授权失败，请在微信客户端内重新打开绑定链接');
        }

        $request->session()->put(self::OCR_ATTEMPT_KEY, $attempts + 1);

        return redirect()->away($wechat->oauthRedirectUrl(
            route('wechat.bind', ['role' => $role]),
            $role
        ));
    }

    /**
     * 渲染绑定页（未拿到 openid 时用于展示错误提示）
     */
    private function render(WechatService $wechat, LessonReminderService $reminders, string $role, string $error = ''): View
    {
        return view('wechat-bind', [
            'role' => $role,
            'organizations' => $this->organizationList(),
            'bindings' => new \Illuminate\Database\Eloquent\Collection,
            'organizationNames' => $this->organizationNames(),
            'tomorrow' => [],
            'tomorrowLabel' => $this->tomorrowLabel(),
            'configured' => $wechat->configured(),
            'pageError' => $error,
        ]);
    }

    private function bindingsFor(string $openid, string $role): \Illuminate\Database\Eloquent\Collection
    {
        return WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', $openid)
            ->where('target_type', $role)
            ->orderBy('bound_at')
            ->get();
    }

    /**
     * 绑定的身份在明天的课程（跨机构合并，按时间排）
     *
     * @return array<int, array<string, string>>
     */
    private function tomorrowLessons(LessonReminderService $reminders, string $openid, string $role): array
    {
        $date = $reminders->dateOf();
        $lessons = [];

        foreach ($this->bindingsFor($openid, $role)->groupBy('organization_code') as $orgCode => $bindings) {
            foreach ($reminders->lessonsForOpenid((string) $orgCode, $openid, $role, $date) as $lesson) {
                $lessons[] = $lesson;
            }
        }

        usort($lessons, fn (array $a, array $b) => strcmp($a['start_at'], $b['start_at']));

        return $lessons;
    }

    private function tomorrowLabel(): string
    {
        $day = BookingWindow::now()->addDay();
        $week = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

        return $day->format('n月j日').' '.$week[$day->dayOfWeek];
    }

    /**
     * 单 openid 节流；名单接口与绑定提交共用
     */
    private function throttled(string $openid): bool
    {
        $limit = max(1, (int) config('wechat.bind.limit_per_minute', 20));
        $key = 'wechat_bind_hits_'.md5($openid).'_'.BookingWindow::now()->format('YmdHi');

        if (! Cache::has($key)) {
            Cache::put($key, 0, 90);
        }

        return Cache::increment($key) > $limit;
    }

    private function role(Request $request): string
    {
        $role = strtolower(trim((string) ($request->query('role') ?? $request->input('role'))));

        return $role === WechatBinding::TYPE_COACH ? WechatBinding::TYPE_COACH : WechatBinding::TYPE_STUDENT;
    }

    private function roleLabel(string $role): string
    {
        return $role === WechatBinding::TYPE_COACH ? '教练' : '学员';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // 允许填 +86 前缀，统一剥掉再比对（档案里的手机号通常不含前缀）
        return str_starts_with($digits, '86') && strlen($digits) === 13 ? substr($digits, 2) : $digits;
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    private function organizationList(): array
    {
        return Organization::query()
            ->orderBy('id')
            ->get(['code', 'name'])
            ->map(fn (Organization $org) => ['code' => $org->code, 'name' => $org->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function organizationNames(): array
    {
        return Organization::query()->pluck('name', 'code')->all();
    }

    /**
     * @return array<int, string>
     */
    private function organizationCodes(): array
    {
        return Organization::query()->pluck('code')->all();
    }
}
