<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * 后台管理的「当前管理机构」上下文
 *
 * 业务数据的机构隔离由 OrganizationScope 完成（只认登录用户的 organization_code），
 * 但总管理员登录后需要管理别的机构，直接改全局 Scope 会波及约课/聊天/固定场等既有业务。
 * 所以后台单独引入这层上下文：
 * - 总管理员：可管理机构 = 全部机构，当前机构存在 session 里（默认自身所属机构）
 * - 机构管理员：可管理机构 = 自身所属机构，当前机构恒等于自身所属机构，切换请求一律拒绝
 *
 * 后台所有读写都拿 currentCode() 显式过滤（模型查询需 withoutGlobalScope），
 * 这样既有业务的隔离口径完全不动。
 */
class AdminContext
{
    /** session 键：总管理员当前管理的机构 code */
    private const SESSION_KEY = 'admin.current_organization_code';

    /** 当前登录用户（未登录返回 null） */
    public function user(): ?User
    {
        /** @var User|null $user */
        $user = auth('web')->user();

        return $user;
    }

    /** 是否总管理员 */
    public function isSuperAdmin(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** 是否机构管理员（不含总管理员） */
    public function isOrgAdmin(): bool
    {
        return $this->user()?->isOrgAdmin() ?? false;
    }

    /** 是否能进后台 */
    public function isManager(): bool
    {
        return $this->isSuperAdmin() || $this->isOrgAdmin();
    }

    /**
     * 当前登录者自己的机构 code（机构管理员的唯一可管理机构）
     */
    public function ownCode(): string
    {
        return (string) ($this->user()?->organization_code ?? '');
    }

    /**
     * 可管理的机构 code 清单
     *
     * @return list<string>
     */
    public function manageableCodes(): array
    {
        if (! $this->isManager()) {
            return [];
        }

        // 机构管理员：只有自己所属机构
        if (! $this->isSuperAdmin()) {
            $own = $this->ownCode();

            return $own === '' ? [] : [$own];
        }

        return Organization::query()->orderBy('id')->pluck('code')->all();
    }

    /**
     * 可管理机构清单（含机构名，页面顶栏切换器用）
     *
     * @return Collection<int, Organization>
     */
    public function manageableOrganizations(): Collection
    {
        if (! $this->isManager()) {
            return new Collection;
        }

        if (! $this->isSuperAdmin()) {
            $own = $this->ownCode();

            return $own === ''
                ? new Collection
                : Organization::query()->where('code', $own)->get();
        }

        return Organization::query()->orderBy('id')->get();
    }

    /**
     * 能否管理该机构（越权校验的唯一入口）
     */
    public function canManage(?string $orgCode): bool
    {
        $code = (string) $orgCode;

        return $code !== '' && in_array($code, $this->manageableCodes(), true);
    }

    /**
     * 当前管理的机构 code
     *
     * 总管理员：session 里存的选中机构；缺失或机构已删除时回落自身所属机构。
     * 机构管理员：恒等于自身所属机构（session 里的值一概忽略，防越权）。
     */
    public function currentCode(): string
    {
        if (! $this->isManager()) {
            return '';
        }

        if (! $this->isSuperAdmin()) {
            return $this->ownCode();
        }

        $picked = (string) Session::get(self::SESSION_KEY, '');

        if ($picked !== '' && $this->canManage($picked)) {
            return $picked;
        }

        return $this->ownCode();
    }

    /**
     * 切换总管理员当前管理的机构
     *
     * @return bool 切换成功返回 true；非总管理员或机构不可管理返回 false
     */
    public function switchTo(?string $orgCode): bool
    {
        if (! $this->isSuperAdmin() || ! $this->canManage($orgCode)) {
            return false;
        }

        $code = (string) $orgCode;

        if (Session::get(self::SESSION_KEY) === $code) {
            return true;
        }

        Session::put(self::SESSION_KEY, $code);

        Log::info('后台切换管理机构', [
            'operator_id' => $this->user()?->getKey(),
            'organization_code' => $code,
        ]);

        return true;
    }

    /**
     * 当前管理机构的展示信息（机构名 + 机构标识 + 认证码）
     *
     * @return array<string, mixed>|null 机构不存在时返回 null
     */
    public function currentOrganization(): ?array
    {
        $code = $this->currentCode();

        if ($code === '') {
            return null;
        }

        /** @var Organization|null $org */
        $org = Organization::query()->where('code', $code)->first();

        if (! $org) {
            return null;
        }

        return [
            'code' => $org->code,
            'name' => $org->name,
            'auth_code' => $org->auth_code,
            'initialized' => $org->isInitialized(),
        ];
    }
}
