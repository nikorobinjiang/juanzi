<?php

namespace App\Console\Commands;

use App\Models\FixedSchedule;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Services\FixedScheduleService;
use App\Services\StudentProfileService;
use App\Support\BookingWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 固定课表导入
 *
 * 数据来源：database/data/fixed_schedules.php（人工核对过的结构化清单）
 * 流程：写模板 → 展开固定场窗口（默认 2 周：本周 + 下周）写入 booking_records → 学员建档
 *
 * 固定场只提前展开两周，之后由用户打开页面时自动滚动补齐（见 EnsureFixedScheduleWindow 中间件），
 * 因此这里不需要（也不应该）一次展开 6 个月。
 *
 * 用法：
 *   php artisan fixed-schedules:import --dry-run          # 只输出核对清单，不写库
 *   php artisan fixed-schedules:import --org=tennis_a     # 正式导入
 */
class ImportFixedSchedules extends Command
{
    protected $signature = 'fixed-schedules:import
        {--dry-run : 只生成核对清单，不写库}
        {--weeks= : 生成周数，默认取 config(doubao.fixed_schedule.weeks)（2 周）}
        {--start= : 展开起始周一，默认固定场窗口起点（本周一）}
        {--org= : 机构 code，默认为系统中唯一的机构}
        {--file= : 数据文件路径，默认 database/data/fixed_schedules.php}';

    protected $description = '导入固定课表（每周固定场次），展开为未来若干周的预约记录';

    /** 核对清单输出路径（相对 storage/app） */
    private const REVIEW_FILE = 'fixed-schedules-review.md';

    public function handle(FixedScheduleService $service, StudentProfileService $profiles): int
    {
        $file = (string) ($this->option('file') ?: database_path('data/fixed_schedules.php'));

        if (! is_file($file)) {
            $this->error('找不到数据文件：'.$file);
            $this->line('请先把核对后的固定课表整理成该文件（返回数组，每项一个固定场次）。');

            return self::FAILURE;
        }

        $rows = require $file;

        if (! is_array($rows) || $rows === []) {
            $this->error('数据文件内容为空或格式不正确（应返回数组）。');

            return self::FAILURE;
        }

        $orgCode = $this->resolveOrgCode();

        if ($orgCode === '') {
            $this->error('无法确定机构：请用 --org=机构code 显式指定，或保证系统中只有一个机构。');

            return self::FAILURE;
        }

        $weeks = (int) ($this->option('weeks') ?: BookingWindow::fixedWeeks());
        $start = trim((string) $this->option('start')) !== ''
            ? Carbon::parse((string) $this->option('start'))->startOfWeek(Carbon::MONDAY)->startOfDay()
            : BookingWindow::fixedStart();

        $templates = $this->normalizeRows($rows);

        $this->info(sprintf(
            '机构：%s ｜ 固定场次：%d 条 ｜ 生成 %d 周（自 %s 起）',
            $orgCode,
            count($templates),
            $weeks,
            $start->format('Y-m-d')
        ));

        $this->line(sprintf(
            '固定场窗口：%s ~ %s ｜ 约课记录展示窗口：%s ~ %s（历史记录不展示）',
            BookingWindow::fixedStart()->format('Y-m-d'),
            BookingWindow::fixedEnd()->copy()->subSecond()->format('Y-m-d H:i'),
            BookingWindow::displayStart()->format('Y-m-d'),
            BookingWindow::displayEnd()->copy()->subSecond()->format('Y-m-d H:i')
        ));

        if ($this->option('dry-run')) {
            // 事务内完整预演一遍（写模板 + 展开若干周），结束回滚，不落库
            DB::beginTransaction();

            try {
                $this->upsertTemplates($templates, $orgCode, $service);
                $result = $service->materialize($weeks, $orgCode, $start);
            } finally {
                DB::rollBack();
            }

            $path = $this->writeReview($templates, $weeks, $start, $result);
            $this->summarizeTemplates($templates);
            $this->warn(sprintf(
                'dry-run 完成（事务已回滚，未落库）：预计写入预约记录 %d 条 ｜ 与库内已有记录冲突跳过 %d 条 ｜ 表内重叠照录 %d 条',
                $result['created'],
                count($result['conflicts']),
                count($result['overlaps'])
            ));
            $this->reportConflicts($result['conflicts'], $result['overlaps']);
            $this->info('核对清单：storage/app/'.$path);

            return self::SUCCESS;
        }

        // 1) 写入模板（按 机构+场地+星期+开始时间+学员 幂等：已存在则更新，不重复插）
        [$created, $updated] = $this->upsertTemplates($templates, $orgCode, $service);

        // 2) 展开为预约记录
        $result = $service->materialize($weeks, $orgCode, $start);

        // 3) 学员建档（用途场不建档）
        //    一格多人（1v2/两名学员同场，如「庞丽萍，边萍霞」「郑侃、孟全丰1v2」）按分隔符拆开分别建档
        $profilesCreated = 0;
        $seen = [];

        foreach ($templates as $row) {
            if (! $row['is_student']) {
                continue;
            }

            foreach ($this->splitStudentNames((string) $row['student_name']) as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                if ($profiles->ensure($name, (string) $row['coach_name'], $orgCode)) {
                    $profilesCreated++;
                }
            }
        }

        $path = $this->writeReview($templates, $weeks, $start, $result);

        $this->info(sprintf(
            '导入完成：模板新增 %d / 更新 %d ｜ 记录新增 %d / 跳过重复 %d / 冲突跳过 %d / 表内重叠照录 %d ｜ 新学员档案 %d',
            $created,
            $updated,
            $result['created'],
            $result['skipped'],
            count($result['conflicts']),
            count($result['overlaps']),
            $profilesCreated
        ));

        $this->reportConflicts($result['conflicts'], $result['overlaps']);
        $this->line('核对清单：storage/app/'.$path);

        Log::info('固定课表导入完成', [
            'organization_code' => $orgCode,
            'weeks' => $weeks,
            'templates_created' => $created,
            'templates_updated' => $updated,
            'records_created' => $result['created'],
            'records_skipped' => $result['skipped'],
            'conflicts' => count($result['conflicts']),
            'overlaps' => count($result['overlaps']),
        ]);

        return self::SUCCESS;
    }

    /**
     * 把「一格多人」的学员名拆成单个学员
     *
     * 例：庞丽萍，边萍霞 → [庞丽萍, 边萍霞]；郑侃、孟全丰1v2 → [郑侃, 孟全丰]
     *
     * @return array<int, string>
     */
    private function splitStudentNames(string $name): array
    {
        $parts = preg_split('/[、，,\/／\s]+/u', $name) ?: [$name];
        $names = [];

        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/(1v2|1V2|1对2|一对一)$/u', '', trim($part)));

            if ($part !== '') {
                $names[] = $part;
            }
        }

        return $names === [] ? [trim($name)] : $names;
    }

    /**
     * 写入固定课表模板（幂等：机构+场地+星期+开始时间+学员 相同则更新）
     *
     * @param  array<int, array<string, mixed>>  $templates
     * @return array{0: int, 1: int}  [新增数, 更新数]
     */
    private function upsertTemplates(array $templates, string $orgCode, FixedScheduleService $service): array
    {
        $created = 0;
        $updated = 0;

        foreach ($templates as $row) {
            $existing = FixedSchedule::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $orgCode)
                ->where('venue', $row['venue'])
                ->where('weekday', $row['weekday'])
                ->where('start_time', $row['start_time'])
                ->where('student_name', $row['student_name'])
                ->first();

            if ($existing) {
                $existing->fill($row + ['organization_code' => $orgCode])->save();
                $updated++;
                continue;
            }

            $service->createTemplate($row, $orgCode);
            $created++;
        }

        return [$created, $updated];
    }

    /**
     * 机构推断：显式 --org 优先，否则取系统中唯一机构
     */
    private function resolveOrgCode(): string
    {
        $org = trim((string) $this->option('org'));

        if ($org !== '') {
            return $org;
        }

        $codes = Organization::query()->pluck('code');

        return $codes->count() === 1 ? (string) $codes->first() : '';
    }

    /**
     * 数据规整：校验必填、时间归一化、补默认值
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $templates = [];
        $service = app(FixedScheduleService::class);

        foreach ($rows as $i => $row) {
            $time = trim((string) ($row['time'] ?? ''));

            $startTime = (string) ($row['start_time'] ?? '');
            $endTime = (string) ($row['end_time'] ?? '');

            if (($startTime === '' || $endTime === '') && $time !== '') {
                $parts = preg_split('/\s*[-~至]\s*/u', $time);
                $startTime = $startTime ?: (string) ($parts[0] ?? '');
                $endTime = $endTime ?: (string) ($parts[1] ?? '');
            }

            $weekday = (int) ($row['weekday'] ?? 0);
            $venue = trim((string) ($row['venue'] ?? ''));
            $student = trim((string) ($row['student_name'] ?? ''));

            if ($weekday < 1 || $weekday > 7 || $venue === '' || $student === '' || $startTime === '' || $endTime === '') {
                $this->warn(sprintf('第 %d 条数据字段不完整，已跳过：%s', $i + 1, json_encode($row, JSON_UNESCAPED_UNICODE)));

                continue;
            }

            // 复用服务的归一化（'7.30' → '07:30'）
            $templates[] = $service->normalizeTemplate($row + [
                'venue' => $venue,
                'weekday' => $weekday,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'student_name' => $student,
            ]);
        }

        return $templates;
    }

    /**
     * 生成人可核对的清单（Markdown），返回相对 storage/app 的路径
     *
     * @param  array<int, array<string, mixed>>  $templates
     * @param  array{created: int, skipped: int, conflicts: array<int, array<string, string>>, overlaps: array<int, array<string, string>>}  $result
     */
    private function writeReview(array $templates, int $weeks, Carbon $start, array $result): string
    {
        $lines = [];
        $lines[] = '# 固定课表核对清单';
        $lines[] = '';
        $lines[] = '- 生成时间：'.now('Asia/Shanghai')->format('Y-m-d H:i');
        $lines[] = '- 固定场次：'.count($templates).' 条';
        $lines[] = '- 展开周数：'.$weeks.' 周（自 '.$start->format('Y-m-d').'，固定场窗口 = 本周 + 下周，之后由打开页面自动补齐）';
        $lines[] = '- 约课记录展示窗口：'.BookingWindow::displayStart()->format('Y-m-d').' ~ '
            .BookingWindow::displayEnd()->copy()->subSecond()->format('Y-m-d H:i').'（历史记录不展示）';
        $lines[] = '- 预约记录：新增 '.$result['created'].' 条，跳过重复 '.$result['skipped'].' 条，冲突跳过 '.count($result['conflicts']).' 条，'
            .'表内重叠照录 '.count($result['overlaps']).' 条';
        $lines[] = '';
        $lines[] = '| 区域 | 场地 | 星期 | 时间 | 学员/用途 | 教练 | 不拼 | 建档 | 备注 |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- |';

        foreach ($this->sortTemplates($templates) as $t) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s-%s | %s | %s | %s | %s | %s |',
                $t['region'],
                $t['venue'],
                FixedSchedule::WEEKDAY_LABELS[$t['weekday']] ?? $t['weekday'],
                $t['start_time'],
                $t['end_time'],
                $t['student_name'],
                $t['coach_name'] ?: '-',
                $t['exclusive'] ? '是' : '',
                $t['is_student'] ? '是' : '否',
                $t['remark'] ?: ''
            );
        }

        if ($result['conflicts']) {
            $lines[] = '';
            $lines[] = '## 冲突跳过明细（已有记录占用，未覆盖）';
            $lines[] = '';
            foreach ($result['conflicts'] as $c) {
                $lines[] = '- '.$c['time'].' '.$c['venue'].' '.$c['template'].' ↔ 已占用：'.$c['conflict_with'];
            }
        }

        if (! empty($result['overlaps'])) {
            $lines[] = '';
            $lines[] = '## 表内重叠（同一片场地同一时间多人/多用途，已按表照录）';
            $lines[] = '';
            $lines[] = '> 按（场次 ↔ 同场对象）归并，显示涉及周数；这些记录都会生成，不会丢失。';
            $lines[] = '';

            $grouped = [];

            foreach ($result['overlaps'] as $o) {
                $key = $o['template'].'|'.$o['overlap_with'].'|'.substr($o['time'], 11);

                $grouped[$key] ??= ['count' => 0, 'first' => substr($o['time'], 0, 10), 'last' => substr($o['time'], 0, 10)];
                $grouped[$key]['count']++;
                $grouped[$key]['last'] = substr($o['time'], 0, 10);
            }

            ksort($grouped);

            foreach ($grouped as $key => $g) {
                [$template, $with] = explode('|', $key);
                $lines[] = sprintf(
                    '- %s ↔ 同场：%s ｜ %d 周（%s ~ %s）',
                    $template,
                    $with,
                    $g['count'],
                    $g['first'],
                    $g['last']
                );
            }
        }

        $path = storage_path('app/'.self::REVIEW_FILE);

        try {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        } catch (\Throwable $e) {
            // 清单只是旁路产物（写不出来不影响导入结果），例如 storage/app 权限不足时不应中断导入
            $this->warn('核对清单写入失败（不影响导入结果）：'.$e->getMessage());
            Log::warning('固定课表核对清单写入失败', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return self::REVIEW_FILE;
    }

    /**
     * 控制台按区域打印模板条数概览
     *
     * @param  array<int, array<string, mixed>>  $templates
     */
    private function summarizeTemplates(array $templates): void
    {
        $byRegion = [];
        $usage = 0;

        foreach ($templates as $t) {
            $byRegion[$t['region']] = ($byRegion[$t['region']] ?? 0) + 1;
            if (! $t['is_student']) {
                $usage++;
            }
        }

        foreach ($byRegion as $region => $count) {
            $this->line(sprintf('  · %s：%d 条', $region, $count));
        }

        $this->line('其中用途场（不建档）：'.$usage.' 条');
    }

    /**
     * @param  array<int, array<string, string>>  $conflicts
     * @param  array<int, array<string, string>>  $overlaps
     */
    private function reportConflicts(array $conflicts, array $overlaps = []): void
    {
        if ($conflicts) {
            $this->warn('以下场次与已有记录冲突，已跳过（未覆盖原记录）：');

            foreach ($conflicts as $c) {
                $this->line('  · '.$c['time'].' '.$c['venue'].' '.$c['template'].' ↔ '.$c['conflict_with']);
            }
        }

        if ($overlaps) {
            $this->line('以下场次为表内重叠（同场地同时间多人/多用途），已照录：');

            foreach (array_slice($overlaps, 0, 10) as $o) {
                $this->line('  · '.$o['time'].' '.$o['venue'].' '.$o['template'].' ↔ '.$o['overlap_with']);
            }

            if (count($overlaps) > 10) {
                $this->line('  · …其余 '. (count($overlaps) - 10) .' 条见核对清单');
            }
        }
    }

    /**
     * 按 星期 → 开始时间 → 场地 排序，方便人工核对
     *
     * @param  array<int, array<string, mixed>>  $templates
     * @return array<int, array<string, mixed>>
     */
    private function sortTemplates(array $templates): array
    {
        usort($templates, function ($a, $b) {
            return [$a['weekday'], $a['start_time'], $a['venue']] <=> [$b['weekday'], $b['start_time'], $b['venue']];
        });

        return $templates;
    }
}
