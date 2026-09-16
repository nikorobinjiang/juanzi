<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 存量教练回填：把三张业务表里已有的 coach_name 收进 coaches 主数据
 *
 * 数据源（并集去重，按 [机构, 姓名] 维度）：
 * - booking_records：排除已取消记录（取消的课不代表教练在场）
 * - students：学员档案的当前教练
 * - fixed_schedules：每周固定场模板的教练
 *
 * 幂等：入库前先查重（含主名命中），重复执行不会产出重复记录。
 * 后续新增教练由 CoachService::ensureCoach() 自动建档，
 * 也可用 php artisan coaches:sync 增量补漏。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('coaches')) {
            return;
        }

        $rows = collect()
            ->merge($this->fromTable('booking_records'))
            ->merge($this->fromTable('students'))
            ->merge($this->fromTable('fixed_schedules', true));

        $existing = DB::table('coaches')
            ->get(['id', 'organization_code', 'name', 'aliases'])
            ->map(fn ($row) => $row->organization_code.'|'.$row->name)
            ->all();

        $now = now();
        $inserted = 0;

        foreach ($rows as $row) {
            $key = $row['organization_code'].'|'.$row['name'];

            if (in_array($key, $existing, true)) {
                continue;
            }

            DB::table('coaches')->insert([
                'organization_code' => $row['organization_code'],
                'name' => $row['name'],
                'phone' => null,
                'aliases' => json_encode([], JSON_UNESCAPED_UNICODE),
                'active' => true,
                'remark' => '存量数据回填',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $existing[] = $key;
            $inserted++;
        }

        Log::info('教练存量回填', [
            'candidates' => $rows->count(),
            'inserted' => $inserted,
        ]);
    }

    public function down(): void
    {
        // 只清理本次回填产生的记录，手工维护的档案（备注不同）保留
        DB::table('coaches')->where('remark', '存量数据回填')->delete();
    }

    /**
     * 取单表的教练名单
     *
     * @return \Illuminate\Support\Collection<int, array{organization_code: string, name: string}>
     */
    private function fromTable(string $table, bool $includeInactive = false): \Illuminate\Support\Collection
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return collect();
        }

        $query = DB::table($table)
            ->select('organization_code', 'coach_name')
            ->whereNotNull('coach_name')
            ->where('coach_name', '!=', '');

        // 约课记录里已取消的场次不计入教练名单
        if (! $includeInactive && DB::getSchemaBuilder()->hasColumn($table, 'status')) {
            $query->where('status', '!=', 'cancelled');
        }

        return $query->get()
            ->map(fn ($row) => [
                'organization_code' => trim((string) $row->organization_code),
                'name' => trim((string) $row->coach_name),
            ])
            // 机构为空的历史脏数据跳过：主数据必须归属某个机构
            ->filter(fn (array $row) => $row['organization_code'] !== '' && $row['name'] !== '')
            ->unique(fn (array $row) => $row['organization_code'].'|'.$row['name'])
            ->values();
    }
};
