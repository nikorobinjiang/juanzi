<?php

namespace App\Console\Commands;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Student;
use App\Services\CoachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 教练主数据补漏：把三张业务表里出现、但 coaches 表里还没有的教练补进主数据
 *
 * 与迁移 2026_09_16_000002 做的是同一件事，区别在于这个命令可以随时重跑：
 * Excel 导入、页面录入等途径新进来的教练姓名，用它一次性收进主数据。
 *
 * 用法：
 *   php artisan coaches:sync --dry-run          # 只列出待补的教练，不写库
 *   php artisan coaches:sync --org=tennis_a     # 只处理指定机构
 */
class SyncCoaches extends Command
{
    protected $signature = 'coaches:sync
        {--dry-run : 只输出待补清单，不写库}
        {--org= : 机构 code，默认处理全部机构}';

    protected $description = '把约课记录/学员档案/固定场次里的教练补进 coaches 主数据表';

    public function handle(CoachService $coaches): int
    {
        $orgFilter = trim((string) ($this->option('org') ?: ''));

        $rows = collect()
            ->merge($this->namesFrom(BookingRecord::class, true))
            ->merge($this->namesFrom(Student::class))
            ->merge($this->namesFrom(FixedSchedule::class))
            ->filter(fn (array $row) => $orgFilter === '' || $row['organization_code'] === $orgFilter)
            ->unique(fn (array $row) => $row['organization_code'].'|'.$row['name'])
            ->values();

        if ($rows->isEmpty()) {
            $this->info('没有发现需要同步的教练。');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $code = $row['organization_code'];
            $canonical = $coaches->resolveCoachName($row['name'], $code);

            if ($coaches->hasProfile($canonical, $code)) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line('待补：['.$code.'] '.$row['name'].($canonical !== $row['name'] ? ' → 归一为 '.$canonical : ''));

                continue;
            }

            $coaches->ensureCoach($row['name'], null, $code);
            $created++;
        }

        if ($this->option('dry-run')) {
            $this->info('共 '.$rows->count().' 位教练参与检查（dry-run，未写库）。');

            return self::SUCCESS;
        }

        $this->info("教练主数据同步完成：新建 {$created} 位，已存在 {$skipped} 位。");

        return self::SUCCESS;
    }

    /**
     * 取某张业务表里出现过的教练（按机构去重，跳过机构为空的脏数据）
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \Illuminate\Support\Collection<int, array{organization_code: string, name: string}>
     */
    private function namesFrom(string $modelClass, bool $excludeCancelled = false): \Illuminate\Support\Collection
    {
        $query = $modelClass::query()
            ->select('organization_code', 'coach_name')
            ->whereNotNull('coach_name')
            ->where('coach_name', '!=', '');

        if ($excludeCancelled) {
            $query->where('status', '!=', BookingRecord::STATUS_CANCELLED);
        }

        return $query->get()
            ->map(fn ($row) => [
                'organization_code' => trim((string) $row->organization_code),
                'name' => trim((string) $row->coach_name),
            ])
            ->filter(fn (array $row) => $row['organization_code'] !== '' && $row['name'] !== '')
            ->values();
    }
}
