<?php

namespace App\Console\Commands;

use App\Models\BookingRecord;
use App\Models\Scopes\OrganizationScope;
use App\Support\BookingWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 固定场存量清理
 *
 * 固定场现在只保留"本周 + 下周"两周（历史上曾一次性展开 24 周）。
 * 该命令删除窗口之外、尚未开始的固定场记录（fixed_schedule_id 非空 + status=booked）：
 * 已完成 / 已取消的历史记录、以及手工约课记录都不会被删除（手工约课只做展示过滤）。
 *
 * 可重复执行（幂等）。先跑 --dry-run 确认条数，再正式执行。
 *
 * 用法：
 *   php artisan fixed-schedules:prune --dry-run     # 只预览
 *   php artisan fixed-schedules:prune --org=tennis_a
 */
class PruneFixedSchedules extends Command
{
    protected $signature = 'fixed-schedules:prune
        {--weeks= : 保留周数，默认取 config(doubao.fixed_schedule.weeks)}
        {--org= : 只清理指定机构 code，默认清理全部机构}
        {--dry-run : 只统计不删除}';

    protected $description = '清理固定场窗口之外、尚未开始的固定场记录（历史与手工约课记录保留）';

    public function handle(): int
    {
        $weeks = max(1, (int) ($this->option('weeks') ?: BookingWindow::fixedWeeks()));
        $boundary = BookingWindow::fixedStart()->addWeeks($weeks);
        $orgCode = trim((string) $this->option('org'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '固定场保留窗口：%s ~ %s（%d 周）｜ 机构：%s ｜ 模式：%s',
            BookingWindow::fixedStart()->format('Y-m-d'),
            $boundary->copy()->subSecond()->format('Y-m-d H:i'),
            $weeks,
            $orgCode !== '' ? $orgCode : '全部',
            $dryRun ? 'dry-run（不删除）' : '正式删除'
        ));

        $prunable = $this->prunableQuery($boundary, $orgCode);

        // 只统计未开始的固定场记录：boundary 之后的记录一定还没开始，这里再按 end_at 兜一层
        $total = (clone $prunable)->where('end_at', '>', BookingWindow::now())->count();

        if ($total === 0) {
            $this->info('没有需要清理的固定场记录，窗口外数据已是最新状态。');

            return self::SUCCESS;
        }

        $this->reportByOrg($prunable, $total);

        if ($dryRun) {
            $this->warn(sprintf('dry-run 完成：将删除 %d 条固定场记录（未落库）。', $total));

            return self::SUCCESS;
        }

        $deleted = 0;

        // 分块删除，避免一次删太多导致大事务/长锁
        (clone $prunable)
            ->where('end_at', '>', BookingWindow::now())
            ->select('id')
            ->chunkById(1000, function ($rows) use (&$deleted) {
                $ids = $rows->pluck('id')->all();

                $deleted += BookingRecord::withoutGlobalScope(OrganizationScope::class)
                    ->whereIn('id', $ids)
                    ->delete();
            });

        $this->info(sprintf('清理完成：已删除 %d 条窗口外的固定场记录（历史与手工约课记录保留）。', $deleted));

        Log::info('固定场存量清理完成', [
            'organization_code' => $orgCode !== '' ? $orgCode : 'all',
            'weeks' => $weeks,
            'boundary' => $boundary->format('Y-m-d H:i'),
            'matched' => $total,
            'deleted' => $deleted,
        ]);

        return self::SUCCESS;
    }

    /**
     * 待清理集合：固定场来源 + 已约状态 + 落在窗口之后
     *
     * @return \Illuminate\Database\Eloquent\Builder<BookingRecord>
     */
    private function prunableQuery(\Carbon\Carbon $boundary, string $orgCode)
    {
        return BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->whereNotNull('fixed_schedule_id')
            ->where('status', BookingRecord::STATUS_BOOKED)
            ->where('start_at', '>=', $boundary)
            ->when($orgCode !== '', fn ($q) => $q->where('organization_code', $orgCode));
    }

    /**
     * 按机构输出待删除条数，便于执行前核对
     *
     * @param  \Illuminate\Database\Eloquent\Builder<BookingRecord>  $prunable
     */
    private function reportByOrg($prunable, int $total): void
    {
        $rows = (clone $prunable)
            ->where('end_at', '>', BookingWindow::now())
            ->selectRaw('organization_code, count(*) as total, min(start_at) as first_at, max(start_at) as last_at')
            ->groupBy('organization_code')
            ->get();

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  · %s：%d 条（%s ~ %s）',
                $row->organization_code ?: '(未归属)',
                $row->total,
                substr((string) $row->first_at, 0, 16),
                substr((string) $row->last_at, 0, 16)
            ));
        }

        // 手工约课记录不在清理范围内，只提示条数，避免误以为被漏掉
        $manual = BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->whereNull('fixed_schedule_id')
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('start_at', '>=', BookingWindow::displayEnd())
            ->count();

        $this->line(sprintf('  （窗口外还有 %d 条非固定场记录，仅不展示、不删除）', $manual));
        $this->line('  合计待删除：'.$total.' 条');
    }
}
