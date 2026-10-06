<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\AdminContext;
use App\Support\VenuePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 后台：机构与机构认证码
 *
 * - 查看：总管理员看全部机构；机构管理员只看自己所属机构
 * - 重置：只动 organizations.auth_code（6 位），不影响任何业务数据；
 *         新码生效后旧码立即无法用于注册，所以操作必须显式确认（由前端弹层保证），并记日志
 */
class AdminOrganizationService
{
    public function __construct(private readonly AdminContext $admin) {}

    /**
     * 可查看的机构清单（含认证码与账号数）
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $orgs = $this->admin->manageableOrganizations();

        $counts = User::query()
            ->whereIn('organization_code', $orgs->pluck('code')->all())
            ->selectRaw('organization_code, count(*) as total')
            ->groupBy('organization_code')
            ->pluck('total', 'organization_code');

        return $orgs->map(fn (Organization $org) => [
            'code' => $org->code,
            'name' => $org->name,
            'auth_code' => $org->auth_code,
            'initialized' => $org->isInitialized(),
            'venue_mode' => $org->venueMode(),
            'venue_mode_label' => $org->venue_mode_label,
            'users_count' => (int) ($counts[$org->code] ?? 0),
        ])->all();
    }

    /**
     * 单个机构详情（机构管理员取自己机构时用）
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $code): ?array
    {
        if (! $this->admin->canManage($code)) {
            throw new AccessDeniedHttpException('无权查看该机构');
        }

        /** @var Organization|null $org */
        $org = Organization::query()->where('code', $code)->first();

        return $org ? [
            'code' => $org->code,
            'name' => $org->name,
            'auth_code' => $org->auth_code,
            'initialized' => $org->isInitialized(),
            'venue_mode' => $org->venueMode(),
            'venue_mode_label' => $org->venue_mode_label,
        ] : null;
    }

    /**
     * 切换机构的场地模式（按场馆性质差异化约课）
     *
     * - required：约课必须分配场地，并做整场/半场冲突检测（网球馆、羽毛球馆等）
     * - none    ：约课不填场地、不做场地冲突（游泳馆、棋院等），只保留教练冲突与营业时段
     *
     * 只改机构属性，不动任何约课记录：历史记录里已写的场地原样保留，
     * 之后新建/修改的课才按新模式走。
     *
     * @return array<string, mixed>
     */
    public function setVenueMode(string $code, string $mode): array
    {
        if (! in_array($mode, Organization::VENUE_MODES, true)) {
            throw ValidationException::withMessages(['venue_mode' => '场地模式不合法']);
        }

        if (! $this->admin->canManage($code)) {
            throw new AccessDeniedHttpException('无权修改该机构场地模式');
        }

        return DB::transaction(function () use ($code, $mode) {
            /** @var Organization|null $org */
            $org = Organization::query()->where('code', $code)->lockForUpdate()->first();

            if (! $org) {
                throw new AccessDeniedHttpException('机构不存在');
            }

            $old = $org->venueMode();
            $org->venue_mode = $mode;
            $org->save();

            // 请求内缓存立即失效，避免同一请求里后续约课仍按旧模式判场地
            VenuePolicy::flush();

            Log::info('后台切换机构场地模式', [
                'operator_id' => $this->admin->user()?->getKey(),
                'organization_code' => $code,
                'from' => $old,
                'to' => $mode,
            ]);

            return $this->detail($code) ?? [];
        });
    }

    /**
     * 重置机构认证码
     *
     * 行锁防并发：同一瞬间两次重置只会留下一个码，不会互相覆盖成"看不见的码"。
     *
     * @return array<string, mixed>
     */
    public function resetAuthCode(string $code): array
    {
        if (! $this->admin->canManage($code)) {
            throw new AccessDeniedHttpException('无权重置该机构认证码');
        }

        return DB::transaction(function () use ($code) {
            /** @var Organization|null $org */
            $org = Organization::query()->where('code', $code)->lockForUpdate()->first();

            if (! $org) {
                throw new AccessDeniedHttpException('机构不存在');
            }

            $new = Organization::generateAuthCode();
            $org->auth_code = $new;
            $org->save();

            Log::info('后台重置机构认证码', [
                'operator_id' => $this->admin->user()?->getKey(),
                'organization_code' => $code,
            ]);

            return [
                'code' => $org->code,
                'name' => $org->name,
                'auth_code' => $org->auth_code,
                'initialized' => true,
            ];
        });
    }
}
