<?php

namespace App\Console\Commands;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\CoachService;
use Illuminate\Console\Command;

/**
 * 教练档案 ↔ 登录账号绑定
 *
 * coaches 表此前是纯名单，「孟宇」是哪个账号只能靠人工记忆。
 * 本命令负责建立两者的关联（coaches.user_id）：
 * - 批量自动匹配：同机构内 users.name 或 users.username 与教练姓名精确相等时自动绑
 * - 手工精确绑定：对不上或多候选时逐位指定
 *
 * 用法：
 *   php artisan coaches:link --dry-run                            # 先看清单，不写库
 *   php artisan coaches:link --org=tennis_a                       # 只处理指定机构并实际绑定
 *   php artisan coaches:link --coach=孟宇 --user=mengyu           # 手工绑定（--user 支持登录名或用户 id）
 *   php artisan coaches:link --coach=孟宇 --unlink                # 解除绑定
 *   php artisan coaches:link --coach=孟宇 --user=mengyu --force   # 已绑其他账号时强制改绑
 */
class LinkCoaches extends Command
{
    protected $signature = 'coaches:link
        {--org= : 机构 code，默认处理全部机构}
        {--coach= : 指定教练姓名/别名，配合 --user 精确绑定或配合 --unlink 解绑}
        {--user= : 登录账号 username 或用户 id，配合 --coach 使用}
        {--unlink : 解除该教练已绑定的账号}
        {--force : 教练已绑其他账号时强制覆盖}
        {--dry-run : 只输出清单，不写库}';

    protected $description = '把教练档案与登录账号关联起来（批量自动匹配 / 手工指定）';

    /** 绑定状态的中文标签，输出时对齐业务语言 */
    private const STATUS_LABELS = [
        CoachService::LINK_LINKED => '已绑定',
        CoachService::LINK_ALREADY => '保持不变',
        CoachService::LINK_UNLINKED => '已解绑',
        CoachService::LINK_MATCHED => '可绑定',
        CoachService::LINK_NO_MATCH => '无候选',
        CoachService::LINK_MULTI_MATCH => '多候选',
        CoachService::LINK_TAKEN => '账号被占用',
        CoachService::LINK_BOUND_OTHER => '已绑他人',
        CoachService::LINK_CROSS_ORG => '跨机构',
        CoachService::LINK_ERROR => '失败',
    ];

    /** 判定为失败的状况（其余属于「待人工处理」，不算命令失败） */
    private const FAILED_STATUSES = [
        CoachService::LINK_ERROR,
        CoachService::LINK_TAKEN,
        CoachService::LINK_BOUND_OTHER,
        CoachService::LINK_CROSS_ORG,
    ];

    public function handle(CoachService $coaches): int
    {
        $orgFilter = trim((string) ($this->option('org') ?: ''));
        $coachInput = trim((string) ($this->option('coach') ?: ''));
        $dryRun = (bool) $this->option('dry-run');

        return $coachInput !== ''
            ? $this->linkSingle($coaches, $coachInput, $orgFilter, $dryRun)
            : $this->linkBatch($coaches, $orgFilter, $dryRun);
    }

    /**
     * 手工模式：按教练姓名 + 账号精确定位一条绑定关系
     */
    private function linkSingle(CoachService $coaches, string $raw, string $orgFilter, bool $dryRun): int
    {
        $canonical = $coaches->resolveCoachName($raw, $orgFilter !== '' ? $orgFilter : null);

        $found = Coach::withoutGlobalScope(OrganizationScope::class)
            ->where('name', $canonical)
            ->when($orgFilter !== '', fn ($query) => $query->where('organization_code', $orgFilter))
            ->orderBy('organization_code')
            ->get();

        if ($found->isEmpty()) {
            $suffix = $canonical !== $raw ? "（归一为 {$canonical}）" : '';
            $this->error("找不到教练「{$raw}」{$suffix}，先用 php artisan coaches:sync 补建档案。");

            return self::FAILURE;
        }

        if ($found->count() > 1) {
            $orgs = $found->pluck('organization_code')->implode('、');
            $this->error("多个机构都有教练「{$canonical}」（{$orgs}），请用 --org= 指定机构。");

            return self::FAILURE;
        }

        /** @var Coach $coach */
        $coach = $found->first();
        $code = (string) $coach->organization_code;

        if ($this->option('unlink')) {
            return $this->unlinkSingle($coaches, $coach, $code, $dryRun);
        }

        $userKey = trim((string) ($this->option('user') ?: ''));

        if ($userKey === '') {
            $this->error("手工绑定需要给出账号：--user=登录名 或 --user=用户id（解绑请用 --unlink）。当前教练：[{$code}] {$coach->name}");

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = $coaches->userByKey($userKey, $code);

        if (! $user) {
            $this->error("[{$code}] 找不到账号「{$userKey}」，必须是本机构的登录名或用户 id。");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line("[{$code}] {$coach->name} → {$user->username}（待绑定，dry-run 未写库）");

            return self::SUCCESS;
        }

        return $this->reportRow($coaches->linkUser($coach, $user, (bool) $this->option('force')));
    }

    /**
     * 手工解绑单个教练
     */
    private function unlinkSingle(CoachService $coaches, Coach $coach, string $code, bool $dryRun): int
    {
        /** @var User|null $bound */
        $bound = $coach->user_id !== null ? User::find($coach->user_id) : null;

        if (! $bound) {
            $this->warn("[{$code}] {$coach->name}：本来就没有绑定账号。");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line("[{$code}] {$coach->name} → 解除 {$bound->username}（dry-run 未写库）");

            return self::SUCCESS;
        }

        return $this->reportRow($coaches->unlinkUser($coach));
    }

    /**
     * 批量模式：按机构预览并执行自动匹配
     */
    private function linkBatch(CoachService $coaches, string $orgFilter, bool $dryRun): int
    {
        $codes = $orgFilter !== ''
            ? [$orgFilter]
            : Coach::withoutGlobalScope(OrganizationScope::class)
                ->distinct()
                ->orderBy('organization_code')
                ->pluck('organization_code')
                ->filter(fn ($code) => trim((string) $code) !== '')
                ->values()
                ->all();

        if ($codes === []) {
            $this->info('coaches 表里还没有教练档案。');

            return self::SUCCESS;
        }

        $tally = [];
        $failed = false;

        foreach ($codes as $code) {
            $report = $coaches->previewLinks($code);

            if ($report === []) {
                continue;
            }

            $this->info('机构：'.$code);

            foreach ($report as $row) {
                // 非自动命中的项只汇报不写入；dry-run 下全部只汇报
                $result = (! $dryRun && $row['status'] === CoachService::LINK_MATCHED)
                    ? $coaches->linkUser($row['coach'], $row['user'])
                    : $row;

                $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;

                if ($this->reportRow($result) === self::FAILURE) {
                    $failed = true;
                }
            }

            $this->line('');
        }

        $this->info($this->summary($tally));

        if ($dryRun) {
            $this->warn('dry-run 模式，未写库；确认清单无误后去掉 --dry-run 再跑一次。');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 输出一行绑定结果，并给出本次是否算失败
     *
     * @param  array{status: string, message: string, coach: Coach, user: User|null, candidates: \Illuminate\Support\Collection<int, User>}  $result
     */
    private function reportRow(array $result): int
    {
        $coach = $result['coach'];
        $label = self::STATUS_LABELS[$result['status']] ?? $result['status'];
        $line = '  · ['.$coach->organization_code.'] '.$coach->name.'：'.$label.' — '.$result['message'];
        $failed = in_array($result['status'], self::FAILED_STATUSES, true);

        if ($failed) {
            $this->error($line);
        } else {
            $this->line($line);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 汇总行：「共 17 位：已绑定 5 / 无候选 9 ...」
     *
     * @param  array<string, int>  $tally
     */
    private function summary(array $tally): string
    {
        if ($tally === []) {
            return '没有需要处理的教练。';
        }

        $parts = [];

        foreach (self::STATUS_LABELS as $status => $label) {
            if (($tally[$status] ?? 0) > 0) {
                $parts[] = $label.' '.$tally[$status];
            }
        }

        return '共 '.array_sum($tally).' 位教练：'.implode(' / ', $parts).'。';
    }
}
