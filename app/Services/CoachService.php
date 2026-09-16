<?php

namespace App\Services;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 教练领域服务：所有教练读写的唯一入口
 *
 * 解决的问题：教练原本只是三张业务表里的 coach_name 字符串，
 * 同一教练的多种写法（王教练/小王/王明）互不相认，名单靠运行时去重，改名要三表批量刷。
 *
 * 本服务对外提供四个能力：
 * - resolveCoachName()：别名/称谓归一，返回规范主名（无档案时原样返回）
 * - ensureCoach()    ：新教练自动建档（已存在只补手机号，停用教练不自动复活）
 * - activeNames()    ：在职教练名单（给 AI 上下文用）
 * - syncRename()     ：改主名 + 旧名入别名 + 供调用方刷新业务表
 *
 * 机构隔离：web 请求下走模型全局 Scope；CLI / 批处理需显式传机构 code。
 */
class CoachService
{
    /** 称呼后缀：「王教练」「王老师」都归到「王」 */
    private const TITLE_SUFFIXES = ['教练', '老师'];

    /** 绑定结果状态：绑定成功 */
    public const LINK_LINKED = 'linked';

    /** 绑定结果状态：与期望状态一致，无需变更（已绑该账号 / 本来就没绑） */
    public const LINK_ALREADY = 'already';

    /** 绑定结果状态：解除绑定成功 */
    public const LINK_UNLINKED = 'unlinked';

    /** 绑定结果状态：候选恰好一个，可以绑 */
    public const LINK_MATCHED = 'matched';

    /** 绑定结果状态：没有任何候选账号 */
    public const LINK_NO_MATCH = 'no_match';

    /** 绑定结果状态：同机构多个同名候选，需人工指定 */
    public const LINK_MULTI_MATCH = 'multi_match';

    /** 绑定结果状态：该账号已被别的教练占用 */
    public const LINK_TAKEN = 'taken';

    /** 绑定结果状态：教练已绑了另一个账号 */
    public const LINK_BOUND_OTHER = 'bound_other';

    /** 绑定结果状态：教练与账号跨机构 */
    public const LINK_CROSS_ORG = 'cross_org';

    /** 绑定结果状态：写入失败 */
    public const LINK_ERROR = 'error';

    /**
     * 请求内缓存：机构 code（空串=不限机构）=> 教练集合
     *
     * @var array<string, Collection<int, Coach>>
     */
    private array $cache = [];

    /**
     * 别名/称谓归一：返回教练档案里的规范名
     *
     * 命中顺序：主名精确 → 剥离称谓后命中主名 → 别名命中 → 原样返回。
     * 没有对应档案时返回原输入，保证未建档教练不会被改写丢了字。
     */
    public function resolveCoachName(string $raw, ?string $orgCode = null): string
    {
        $name = trim($raw);

        if ($name === '') {
            return '';
        }

        $stripped = $this->stripTitle($name);
        $coaches = $this->allFor($orgCode);

        foreach ($coaches as $coach) {
            if ($coach->name === $name || ($stripped !== '' && $coach->name === $stripped)) {
                return $coach->name;
            }
        }

        foreach ($coaches as $coach) {
            if (in_array($name, $coach->aliases_list, true)
                || ($stripped !== '' && in_array($stripped, $coach->aliases_list, true))) {
                return $coach->name;
            }
        }

        return $name;
    }

    /**
     * 在职教练名单（字典序），供 AI 上下文使用
     *
     * @return array<int, string>
     */
    public function activeNames(?string $orgCode = null): array
    {
        return $this->allFor($orgCode)
            ->filter(fn (Coach $coach) => $coach->active)
            ->map(fn (Coach $coach) => $coach->name)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 确保教练档案存在（新教练自动建档）
     *
     * 已存在时：别名命中也会落到主档案；手机号只在空缺时补，不覆盖。
     * 停用（离职）教练不自动复活，避免在约课里被重新排上。
     *
     * @return Coach|null 机构未知或建档失败时返回 null
     */
    public function ensureCoach(string $name, ?string $phone = null, ?string $orgCode = null): ?Coach
    {
        $raw = trim($name);
        $code = $orgCode ?? $this->orgCode();

        if ($raw === '' || $code === '') {
            return null;
        }

        // 别名先归一，保证「小王」「王」落到同一条档案
        $canonical = $this->resolveCoachName($raw, $code);

        try {
            /** @var Coach|null $coach */
            $coach = Coach::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $code)
                ->where('name', $canonical)
                ->first();

            if ($coach) {
                if ($phone !== null && $phone !== '' && (string) $coach->phone === '') {
                    $coach->phone = $phone;
                    $coach->save();
                    $this->flush($code);
                }

                return $coach;
            }

            $coach = Coach::create([
                'organization_code' => $code,
                'name' => $canonical,
                'phone' => $phone ?: null,
                'aliases' => [],
                'active' => true,
                'remark' => '系统自动建档',
            ]);

            // 输入写法与规范名不同时留存别名，下次直接命中
            if ($raw !== $canonical) {
                $coach->aliases = array_values(array_unique(array_merge(
                    $coach->aliases_list,
                    [$raw, $this->stripTitle($raw)]
                )));
                $coach->save();
            }

            $this->flush($code);

            return $coach;
        } catch (\Throwable $e) {
            Log::warning('教练自动建档失败', [
                'coach_name' => $raw,
                'organization_code' => $code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 教练改名：更新主数据并把旧写法存进别名
     *
     * - 目标新名已有档案：两者合并（旧名的别名并入新档案，原记录删除）
     * - 主数据没有旧名档案：按新名补建一条，旧名作为别名（来源通常是 Excel 导入的历史写法）
     *
     * @return int 主数据受影响记录数（0 表示主数据原本没有记录）
     */
    public function syncRename(string $oldName, string $newName, ?string $orgCode = null): int
    {
        $oldName = trim($oldName);
        $newName = trim($newName);
        $code = $orgCode ?? $this->orgCode();

        if ($oldName === '' || $newName === '' || $oldName === $newName || $code === '') {
            return 0;
        }

        try {
            $changed = DB::transaction(function () use ($oldName, $newName, $code) {
                /** @var Coach|null $source */
                $source = $this->coachByName($oldName, $code);
                /** @var Coach|null $target */
                $target = $this->coachByName($newName, $code);

                if ($source) {
                    $aliases = array_values(array_unique(array_merge($source->aliases_list, [$oldName])));

                    if ($target) {
                        // 目标档案已存在：合并别名后删掉旧档案（联合唯一约束不允许两条同名）
                        $target->aliases = array_values(array_unique(array_merge($target->aliases_list, $aliases)));
                        $target->save();
                        $source->delete();
                    } else {
                        $source->name = $newName;
                        $source->aliases = $aliases;
                        $source->save();
                    }

                    $this->flush($code);

                    return $target ? 2 : 1;
                }

                // 主数据缺失（历史数据没回填完整）：补建新名档案，旧名进别名
                if ($target) {
                    $target->aliases = array_values(array_unique(array_merge($target->aliases_list, [$oldName])));
                    $target->save();
                } elseif ($code !== '') {
                    Coach::create([
                        'organization_code' => $code,
                        'name' => $newName,
                        'aliases' => [$oldName],
                        'active' => true,
                        'remark' => '改名补建',
                    ]);
                }

                $this->flush($code);

                return 0;
            });

            Log::info('教练主数据改名', [
                'organization_code' => $code,
                'old' => $oldName,
                'new' => $newName,
                'changed' => $changed,
            ]);

            return $changed;
        } catch (\Throwable $e) {
            Log::warning('教练主数据改名失败', [
                'organization_code' => $code,
                'old' => $oldName,
                'new' => $newName,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * 给教练绑定登录账号
     *
     * 校验顺序：双方机构一致 → 账号未被别的教练占用 → 教练未绑其他账号（force 可覆盖）。
     * 一对一语义最终由 coaches_user_id_unique 兜底，但这里先查后写，
     * 不把数据库异常当成正常流程控制。
     *
     * @return array{status: string, message: string, coach: Coach, user: User|null}
     */
    public function linkUser(Coach $coach, User $user, bool $force = false): array
    {
        if ((string) $coach->organization_code !== (string) $user->organization_code) {
            return $this->linkResult(self::LINK_CROSS_ORG, "跨机构：教练属于 [{$coach->organization_code}]，账号属于 [{$user->organization_code}]", $coach, null);
        }

        if ($coach->user_id !== null && (int) $coach->user_id === (int) $user->getKey()) {
            return $this->linkResult(self::LINK_ALREADY, "已绑定该账号（{$user->username}）", $coach, $user);
        }

        $occupant = $this->occupantOf($user, $coach);

        if ($occupant) {
            return $this->linkResult(self::LINK_TAKEN, "账号 {$user->username} 已被教练「{$occupant->name}」占用", $coach, $user);
        }

        if ($coach->user_id !== null && ! $force) {
            $previous = User::find($coach->user_id);

            return $this->linkResult(
                self::LINK_BOUND_OTHER,
                '教练已绑定账号 '.($previous?->username ?: '#'.$coach->user_id).'，先 --unlink 或加 --force',
                $coach,
                $user
            );
        }

        try {
            $coach->user_id = $user->getKey();
            $coach->save();

            $this->flush($coach->organization_code);

            Log::info('教练绑定登录账号', [
                'organization_code' => $coach->organization_code,
                'coach_id' => $coach->getKey(),
                'coach_name' => $coach->name,
                'user_id' => $user->getKey(),
                'username' => $user->username,
                'forced' => $force,
            ]);

            return $this->linkResult(self::LINK_LINKED, "已绑定账号 {$user->username}", $coach, $user);
        } catch (QueryException $e) {
            // 并发下的唯一索引冲突，按占用处理
            Log::warning('教练绑定账号撞上唯一约束', [
                'coach_id' => $coach->getKey(),
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->linkResult(self::LINK_TAKEN, "账号 {$user->username} 已被占用", $coach, $user);
        } catch (\Throwable $e) {
            Log::warning('教练绑定账号失败', [
                'coach_id' => $coach->getKey(),
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->linkResult(self::LINK_ERROR, '绑定失败：'.$e->getMessage(), $coach, $user);
        }
    }

    /**
     * 解除教练与登录账号的关联
     *
     * @return array{status: string, message: string, coach: Coach, user: User|null}
     */
    public function unlinkUser(Coach $coach): array
    {
        if ($coach->user_id === null) {
            return $this->linkResult(self::LINK_ALREADY, '本来就没有绑定账号', $coach, null);
        }

        /** @var User|null $previous */
        $previous = User::find($coach->user_id);
        $label = $previous?->username ?: '#'.$coach->user_id;

        try {
            $coach->user_id = null;
            $coach->save();

            $this->flush($coach->organization_code);

            Log::info('教练解除账号绑定', [
                'organization_code' => $coach->organization_code,
                'coach_id' => $coach->getKey(),
                'coach_name' => $coach->name,
                'username' => $label,
            ]);

            return $this->linkResult(self::LINK_UNLINKED, "已解除账号 {$label}", $coach, null);
        } catch (\Throwable $e) {
            Log::warning('教练解除账号绑定失败', [
                'coach_id' => $coach->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->linkResult(self::LINK_ERROR, '解绑失败：'.$e->getMessage(), $coach, null);
        }
    }

    /**
     * 预览机构内所有教练「能自动绑定到哪个账号」（只读，不写库）
     *
     * 候选规则：同机构内 users.name 或 users.username 与教练姓名精确相等。
     * 注册时昵称常常就是登录名，所以两条都对一遍；仍对不上的交给 coaches:link --coach/--user 手工绑。
     *
     * @return array<int, array{status: string, message: string, coach: Coach, user: User|null, candidates: \Illuminate\Support\Collection<int, User>}>
     */
    public function previewLinks(?string $orgCode = null): array
    {
        $report = [];

        foreach ($this->allFor($orgCode) as $coach) {
            if ($coach->user_id !== null) {
                $bound = User::find($coach->user_id);
                $report[] = $this->linkResult(self::LINK_ALREADY, '已绑定 '.($bound?->username ?: '#'.$coach->user_id), $coach, $bound);

                continue;
            }

            $candidates = $this->userCandidates($coach);
            $first = $candidates->first();

            if ($candidates->isEmpty()) {
                $report[] = $this->linkResult(self::LINK_NO_MATCH, '没有同名/同账号的候选，需手工指定', $coach, null, $candidates);
            } elseif ($candidates->count() > 1) {
                $labels = $candidates->map(fn (User $user) => $user->username)->implode('、');
                $report[] = $this->linkResult(self::LINK_MULTI_MATCH, "有 {$candidates->count()} 个候选（{$labels}），需手工指定", $coach, null, $candidates);
            } elseif ($this->occupantOf($first, $coach)) {
                $occupant = $this->occupantOf($first, $coach);
                $report[] = $this->linkResult(self::LINK_TAKEN, "账号 {$first->username} 已被教练「{$occupant?->name}」占用", $coach, null, $candidates);
            } else {
                $report[] = $this->linkResult(self::LINK_MATCHED, "可绑定到账号 {$first->username}", $coach, $first, $candidates);
            }
        }

        return $report;
    }

    /**
     * 按姓名（经别名归一）查教练档案，手工绑定时用
     */
    public function findByName(string $name, string $orgCode = ''): ?Coach
    {
        $canonical = $this->resolveCoachName($name, $orgCode !== '' ? $orgCode : null);

        return $this->coachByName($canonical, $orgCode);
    }

    /**
     * 按 username 或主键找账号（手工绑定时用）
     */
    public function userByKey(string $key, string $orgCode): ?User
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        $query = User::query()->where('organization_code', $orgCode);

        // 纯数字按主键找，否则按登录名找（都不是就两条都试一次）
        $query->where(ctype_digit($key) ? 'id' : 'username', $key);

        /** @var User|null $user */
        $user = $query->first();

        return $user;
    }

    /**
     * 主数据里是否已有该教练的档案（按规范名查找）
     */
    public function hasProfile(string $name, ?string $orgCode = null): bool
    {
        $name = trim($name);
        $code = $orgCode ?? $this->orgCode();

        if ($name === '') {
            return false;
        }

        return $this->coachByName($name, $code) !== null;
    }

    /**
     * 清空指定机构的缓存（写入后调用，保证下次查询拿到最新档案）
     */
    public function flush(?string $orgCode = null): void
    {
        $code = $orgCode ?? $this->orgCode();

        unset($this->cache[$code === '' ? '*' : $code]);
    }

    /**
     * 当前机构 code；未登录（CLI / 队列）返回空串，调用方需显式传值
     */
    public function orgCode(): string
    {
        return (string) (auth('web')->user()?->organization_code ?? '');
    }

    /**
     * 按机构 + 姓名取档案（脱离全局作用域，CLI 下也可用）
     */
    private function coachByName(string $name, string $code): ?Coach
    {
        $query = Coach::withoutGlobalScope(OrganizationScope::class)->where('name', $name);

        if ($code !== '') {
            $query->where('organization_code', $code);
        }

        /** @var Coach|null $coach */
        $coach = $query->first();

        return $coach;
    }

    /**
     * 机构内的全量教练档案（含停用），带请求内缓存
     *
     * @return Collection<int, Coach>
     */
    private function allFor(?string $orgCode = null): Collection
    {
        $code = $orgCode ?? $this->orgCode();
        $key = $code === '' ? '*' : $code;

        if (! array_key_exists($key, $this->cache)) {
            $query = Coach::withoutGlobalScope(OrganizationScope::class);

            if ($code !== '') {
                $query->where('organization_code', $code);
            }

            $this->cache[$key] = $query->orderBy('name')->get();
        }

        return $this->cache[$key];
    }

    /**
     * 统一的绑定结果结构
     *
     * @param  \Illuminate\Support\Collection<int, User>|null  $candidates
     * @return array{status: string, message: string, coach: Coach, user: User|null, candidates: \Illuminate\Support\Collection<int, User>}
     */
    private function linkResult(
        string $status,
        string $message,
        Coach $coach,
        ?User $user = null,
        ?\Illuminate\Support\Collection $candidates = null
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'coach' => $coach,
            'user' => $user,
            'candidates' => $candidates ?? collect(),
        ];
    }

    /**
     * 某账号是否已被别的教练占用（排除教练自己）
     */
    private function occupantOf(User $user, ?Coach $except = null): ?Coach
    {
        $query = Coach::withoutGlobalScope(OrganizationScope::class)->where('user_id', $user->getKey());

        if ($except?->getKey()) {
            $query->whereKeyNot($except->getKey());
        }

        /** @var Coach|null $occupant */
        $occupant = $query->first();

        return $occupant;
    }

    /**
     * 教练的同机构候选账号：users.name 或 users.username 与教练姓名精确相等
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function userCandidates(Coach $coach): \Illuminate\Support\Collection
    {
        $name = trim((string) $coach->name);

        if ($name === '' || (string) $coach->organization_code === '') {
            return collect();
        }

        return User::query()
            ->where('organization_code', $coach->organization_code)
            ->where(fn ($query) => $query->where('name', $name)->orWhere('username', $name))
            ->orderBy('id')
            ->get();
    }

    /**
     * 剥离称呼后缀：「王教练」→「王」
     *
     * 名字只剩一个字时不再剥离（避免出现空的姓名）。
     */
    private function stripTitle(string $name): string
    {
        foreach (self::TITLE_SUFFIXES as $suffix) {
            if (mb_strlen($name) > mb_strlen($suffix) && str_ends_with($name, $suffix)) {
                return trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)));
            }
        }

        return $name;
    }
}
