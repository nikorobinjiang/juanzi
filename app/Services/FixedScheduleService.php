<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Support\BookingWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 固定课表（每周固定场次）
 *
 * 职责：
 * - materialize()：把模板按周展开写入 booking_records（幂等、内存预检冲突、分块插入）
 * - syncFuture()：模板改动后只重算"未发生的未来记录"，已完成/历史记录保留
 * - cancelFuture()：模板停用 + 未来记录置 cancelled
 * - locate()：按 学员/场地/星期/时间 定位模板
 * - renameCoach()：教练姓氏批量改名（预约记录 / 模板 / 学员档案）
 *
 * 约定：模板只存"星期 + 时间"，具体某天的记录落在 booking_records，
 * 因此"这周小明不来了"只动记录（scope=once），"以后每周都改"才动模板（scope=future）。
 */
class FixedScheduleService
{
    /** 单次批量插入行数 */
    private const INSERT_CHUNK = 500;

    public function __construct(private BookingService $bookings)
    {
    }

    /* -----------------------------------------------------------------
     | 模板
     | ----------------------------------------------------------------- */

    /**
     * 新建模板（导入命令 / 聊天修改共用）
     *
     * @param  array<string, mixed>  $data
     * @param  string  $orgCode  机构 code；CLI 场景必须显式传入
     */
    public function createTemplate(array $data, string $orgCode = ''): FixedSchedule
    {
        return FixedSchedule::create(
            $this->normalizeTemplate($data) + ['organization_code' => $this->resolveOrgCode($orgCode)]
        );
    }

    /**
     * 模板字段归一化（不落库，供导入命令核对/预览复用）
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeTemplate(array $data): array
    {
        return [
            'region' => (string) ($data['region'] ?? FixedSchedule::REGION_DEVELOPMENT),
            'venue' => trim((string) ($data['venue'] ?? '')),
            'weekday' => (int) ($data['weekday'] ?? 1),
            'start_time' => $this->normalizeTime((string) ($data['start_time'] ?? '')),
            'end_time' => $this->normalizeTime((string) ($data['end_time'] ?? '')),
            'student_name' => trim((string) ($data['student_name'] ?? '')),
            'coach_name' => trim((string) ($data['coach_name'] ?? '')),
            'exclusive' => (bool) ($data['exclusive'] ?? false),
            'is_student' => (bool) ($data['is_student'] ?? true),
            'remark' => trim((string) ($data['remark'] ?? '')),
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    /**
     * 机构 code：显式传入优先，其次取登录态
     */
    public function resolveOrgCode(string $orgCode = ''): string
    {
        return $orgCode !== ''
            ? $orgCode
            : (string) (auth('web')->user()?->organization_code ?? '');
    }

    /**
     * 把模板展开写入 booking_records
     *
     * 幂等：同一模板 + 同一开始时间已存在记录时跳过（可重复执行补生成）。
     * 冲突：与已有记录（含本次批量中已排入的）场地占位重叠时跳过并记录明细，不覆盖用户数据。
     *
     * @param  int  $weeks  展开周数
     * @param  string  $orgCode  机构 code；为空时取登录态
     * @param  Carbon|null  $start  起始周一；默认取固定场窗口起点（本周一，见 BookingWindow）
     * @param  bool  $allowSelfOverlap  表内自身重叠（同一片场地同一时段的多个场次）是否照录：默认 true 不丢数据；false 则按冲突跳过
     * @return array{created: int, skipped: int, conflicts: array<int, array<string, string>>, overlaps: array<int, array<string, string>>}
     */
    public function materialize(int $weeks, string $orgCode = '', ?Carbon $start = null, bool $allowSelfOverlap = true): array
    {
        $orgCode = $orgCode !== '' ? $orgCode : (string) (auth('web')->user()?->organization_code ?? '');
        $weeks = max(1, $weeks);
        $start = ($start ?: BookingWindow::fixedStart())
            ->copy()
            ->startOfDay()
            ->startOfWeek(Carbon::MONDAY);

        $rangeEnd = $start->copy()->addWeeks($weeks);

        $templates = FixedSchedule::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('active', true)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        if ($templates->isEmpty()) {
            return ['created' => 0, 'skipped' => 0, 'conflicts' => [], 'overlaps' => []];
        }

        // 已存在的固定场记录：用于幂等去重
        $existingKeys = $this->existingKeys($orgCode, $start, $rangeEnd);

        // 已占用时段索引（按日期分组），用于内存冲突预检（只针对库内已有记录）
        $occupied = $this->occupancyIndex($orgCode, $start, $rangeEnd);
        // 本批次内已排入的时段：用于报告"表内重叠"（默认照录，不丢弃数据）
        $batch = [];

        $rows = [];
        $created = 0;
        $skipped = 0;
        $conflicts = [];
        $overlaps = [];

        foreach ($templates as $tpl) {
            foreach ($this->expandTemplate($tpl, $start, $weeks) as $slot) {
                $key = $tpl->id.'|'.$slot['start_at']->format('Y-m-d H:i');

                if (isset($existingKeys[$key])) {
                    $skipped++;
                    continue;
                }

                $slot += ['venue' => $tpl->venue];

                $conflict = $this->findConflict($occupied, $slot);

                if ($conflict !== null) {
                    $conflicts[] = [
                        'template' => $tpl->summary,
                        'time' => $slot['start_at']->format('Y-m-d H:i'),
                        'venue' => $tpl->venue,
                        'conflict_with' => $conflict,
                    ];
                    continue;
                }

                $label = $tpl->student_name.($tpl->coach_name ? '/'.$tpl->coach_name : '');
                $selfConflict = $this->findConflict($batch, $slot);

                if ($selfConflict !== null) {
                    if (! $allowSelfOverlap) {
                        $conflicts[] = [
                            'template' => $tpl->summary,
                            'time' => $slot['start_at']->format('Y-m-d H:i'),
                            'venue' => $tpl->venue,
                            'conflict_with' => $selfConflict.'（本次导入表内重叠）',
                        ];
                        continue;
                    }

                    $overlaps[] = [
                        'template' => $tpl->summary,
                        'time' => $slot['start_at']->format('Y-m-d H:i'),
                        'venue' => $tpl->venue,
                        'overlap_with' => $selfConflict,
                    ];
                }

                // 登记占用（库内预检与表内重叠报告都基于占用索引）
                $this->registerOccupancy($batch, $slot, $label);

                $rows[] = [
                    'student_name' => $tpl->student_name,
                    'coach_name' => (string) $tpl->coach_name,
                    'start_at' => $slot['start_at'],
                    'end_at' => $slot['end_at'],
                    'venue' => $tpl->venue,
                    'fixed_schedule_id' => $tpl->id,
                    'status' => BookingRecord::STATUS_BOOKED,
                    'remark' => $this->buildRemark($tpl),
                    'organization_code' => $orgCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $created++;
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            BookingRecord::withoutGlobalScope(OrganizationScope::class)->insert($chunk);
        }

        Log::info('固定课表展开完成', [
            'organization_code' => $orgCode,
            'weeks' => $weeks,
            'start' => $start->format('Y-m-d'),
            'range_end' => $rangeEnd->format('Y-m-d'),
            'created' => $created,
            'skipped' => $skipped,
            'conflicts' => count($conflicts),
            'overlaps' => count($overlaps),
        ]);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'conflicts' => $conflicts,
            'overlaps' => $overlaps,
        ];
    }

    /**
     * 补齐固定场窗口（本周 + 下周）
     *
     * 固定场不再一次性展开 6 个月，改由用户每次打开页面时滚动补齐：
     * 以"本周一"为起点展开固定场窗口，已存在的记录由 materialize() 幂等跳过，
     * 已经过去的时段由 expandTemplate() 跳过，因此可安全重复调用。
     *
     * @param  string  $orgCode  机构 code；为空时取登录态（CLI 场景需显式传入）
     * @param  Carbon|null  $now  参考时间（默认当前时间），仅测试需要
     * @return array{created: int, skipped: int, conflicts: array<int, array<string, string>>, overlaps: array<int, array<string, string>>}
     */
    public function ensureWindow(string $orgCode = '', ?Carbon $now = null): array
    {
        $orgCode = $this->resolveOrgCode($orgCode);

        if ($orgCode === '') {
            return ['created' => 0, 'skipped' => 0, 'conflicts' => [], 'overlaps' => []];
        }

        return $this->materialize(
            BookingWindow::fixedWeeks(),
            $orgCode,
            BookingWindow::fixedStart($now)
        );
    }

    /**
     * 模板改动：更新模板 → 删除未来未发生的记录 → 按新模板重新生成
     *
     * 只影响 start_at > now 的 booked 记录；已完成、已取消、历史记录保留不变。
     *
     * @param  array<string, mixed>  $newData  start_time/end_time/venue/coach_name/student_name/remark/weekday/exclusive
     * @return array{deleted: int, created: int, skipped: int, conflicts: array<int, array<string, string>>, overlaps: array<int, array<string, string>>, template: FixedSchedule}
     */
    public function syncFuture(FixedSchedule $tpl, array $newData): array
    {
        $orgCode = (string) $tpl->organization_code;

        return DB::transaction(function () use ($tpl, $newData, $orgCode) {
            foreach (['start_time', 'end_time', 'venue', 'coach_name', 'student_name', 'remark'] as $field) {
                if (array_key_exists($field, $newData) && $newData[$field] !== null) {
                    $tpl->{$field} = trim((string) $newData[$field]);
                }
            }

            if (array_key_exists('weekday', $newData) && $newData['weekday']) {
                $tpl->weekday = (int) $newData['weekday'];
            }

            if (array_key_exists('exclusive', $newData) && $newData['exclusive'] !== null) {
                $tpl->exclusive = (bool) $newData['exclusive'];
            }

            $tpl->start_time = $this->normalizeTime($tpl->start_time);
            $tpl->end_time = $this->normalizeTime($tpl->end_time);
            $tpl->save();

            $now = Carbon::now('Asia/Shanghai');

            $deleted = BookingRecord::withoutGlobalScope(OrganizationScope::class)
                ->where('fixed_schedule_id', $tpl->id)
                ->where('status', BookingRecord::STATUS_BOOKED)
                ->where('start_at', '>', $now)
                ->delete();

            // 起点固定为"本周一"：固定场窗口是滚动的，从配置起始日算会漏掉本周
            $result = $this->materialize(
                BookingWindow::fixedWeeks(),
                $orgCode,
                BookingWindow::fixedStart()
            );

            Log::info('固定课表改动重算', [
                'fixed_schedule_id' => $tpl->id,
                'deleted' => $deleted,
                'created' => $result['created'],
                'conflicts' => count($result['conflicts']),
                'overlaps' => count($result['overlaps']),
            ]);

            return [
                'deleted' => $deleted,
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'conflicts' => $result['conflicts'],
                'overlaps' => $result['overlaps'],
                'template' => $tpl,
            ];
        });
    }

    /**
     * 模板停用 + 未来未发生的记录置为已取消（历史记录保留）
     *
     * @return array{cancelled: int, template: FixedSchedule}
     */
    public function cancelFuture(FixedSchedule $tpl): array
    {
        return DB::transaction(function () use ($tpl) {
            $tpl->active = false;
            $tpl->save();

            $cancelled = BookingRecord::withoutGlobalScope(OrganizationScope::class)
                ->where('fixed_schedule_id', $tpl->id)
                ->where('status', BookingRecord::STATUS_BOOKED)
                ->where('start_at', '>', Carbon::now('Asia/Shanghai'))
                ->update(['status' => BookingRecord::STATUS_CANCELLED, 'updated_at' => now()]);

            Log::info('固定课表停用', [
                'fixed_schedule_id' => $tpl->id,
                'cancelled' => $cancelled,
            ]);

            return ['cancelled' => $cancelled, 'template' => $tpl];
        });
    }

    /**
     * 按 学员 / 场地 / 星期 / 时间 定位模板
     *
     * 与 BookingService::locateTarget() 保持同样的返回约定：唯一命中 success=true，
     * 多条命中 need_info=true 并给出候选，让调用方提示用户补充信息。
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, need_info: bool, template: ?FixedSchedule, candidates: array, message: string}
     */
    public function locate(array $data): array
    {
        $student = trim((string) ($data['student_name'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? ''));
        $weekday = (int) ($data['weekday'] ?? 0);
        $startTime = $this->normalizeTime((string) ($data['start_time'] ?? ''));

        $query = FixedSchedule::query()->where('active', true);

        if ($student !== '') {
            $query->where('student_name', 'like', '%'.$student.'%');
        }
        if ($venue !== '') {
            $query->where('venue', $venue);
        }
        if ($weekday >= 1 && $weekday <= 7) {
            $query->where('weekday', $weekday);
        }
        if ($startTime !== '') {
            $query->where('start_time', $startTime);
        }

        $candidates = $query->orderBy('weekday')->orderBy('start_time')->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => false,
                'need_info' => false,
                'template' => null,
                'candidates' => [],
                'message' => '没有找到符合条件的固定场次。',
            ];
        }

        if ($candidates->count() === 1) {
            return [
                'success' => true,
                'need_info' => false,
                'template' => $candidates->first(),
                'candidates' => [],
                'message' => '',
            ];
        }

        $list = $candidates->map(fn (FixedSchedule $t) => '· '.$t->summary)->implode("\n");

        return [
            'success' => false,
            'need_info' => true,
            'template' => null,
            'candidates' => $candidates->all(),
            'message' => '找到了几个固定场次，请再告诉我更具体的信息（比如星期几或时间）：'."\n".$list,
        ];
    }

    /**
     * 教练姓氏批量改名：预约记录 + 固定课表模板 + 学员档案
     *
     * 主数据 coaches 同步由 CoachService::syncRename() 负责（旧名进别名），
     * 与三张业务表的字符串刷新放在同一事务里。
     *
     * @param  string  $orgCode  限定机构；为空时取当前登录机构，仍未取到则维持原有全表行为（CLI 数据订正）
     * @param  bool  $force  后台改名用：业务表没有记录时也要改主数据（不走"改一个不存在的名字"的短路）
     * @return int 命中的业务记录总条数（不含主数据本身）
     */
    public function renameCoach(string $oldName, string $newName, string $orgCode = '', bool $force = false): int
    {
        $oldName = trim($oldName);
        $newName = trim($newName);

        if ($oldName === '' || $newName === '' || $oldName === $newName) {
            return 0;
        }

        if ($orgCode === '') {
            $orgCode = (string) (auth('web')->user()?->organization_code ?? '');
        }

        // 先确认业务表里确实有这位教练：一处都没有就原样返回 0，
        // 避免"改一个不存在的名字"顺手在主数据里凭空建档
        // 后台改名是拿档案主键改的，名字一定存在，用 force 跳过这道短路
        if (! $force && $this->countCoachRecords($oldName, $orgCode) === 0) {
            return 0;
        }

        // 再改主数据：旧名写进别名，保证历史叫法以后还能归一到新名
        $masterChanged = app(CoachService::class)->syncRename($oldName, $newName, $orgCode);

        // 最后刷业务表的字符串：两者放在同一事务里，避免出现"档案改了、约课没改"的半截状态
        return DB::transaction(function () use ($oldName, $newName, $orgCode, $masterChanged) {
            $scoped = fn ($query) => $orgCode !== '' ? $query->where('organization_code', $orgCode) : $query;

            $bookings = $scoped(BookingRecord::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $oldName)
                ->update(['coach_name' => $newName, 'updated_at' => now()]);

            $templates = $scoped(FixedSchedule::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $oldName)
                ->update(['coach_name' => $newName, 'updated_at' => now()]);

            $students = $scoped(Student::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $oldName)
                ->update(['coach_name' => $newName, 'updated_at' => now()]);

            Log::info('教练改名', [
                'organization_code' => $orgCode,
                'old' => $oldName,
                'new' => $newName,
                'bookings' => $bookings,
                'templates' => $templates,
                'students' => $students,
                'master_changed' => $masterChanged,
            ]);

            return $bookings + $templates + $students;
        });
    }

    /**
     * 该教练在三张业务表里出现过的记录条数（后台删除教练前用它判断影响面）
     */
    public function countCoachRecords(string $coachName, string $orgCode): int
    {
        $scoped = fn ($query) => $orgCode !== '' ? $query->where('organization_code', $orgCode) : $query;

        return $scoped(BookingRecord::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $coachName)->count()
            + $scoped(FixedSchedule::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $coachName)->count()
            + $scoped(Student::withoutGlobalScope(OrganizationScope::class))
                ->where('coach_name', $coachName)->count();
    }

    /**
     * 输出给豆包的固定场次 JSON（仅启用中的模板）
     */
    public function toJsonForAI(): string
    {
        return FixedSchedule::query()
            ->where('active', true)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (FixedSchedule $t) => [
                'id' => $t->id,
                '星期' => $t->weekday_label,
                '时间' => $t->start_time.'-'.$t->end_time,
                '区域' => $t->region,
                '场地' => $t->venue,
                '学员' => $t->student_name,
                '教练' => $t->coach_name,
                '不拼' => (bool) $t->exclusive,
            ])
            ->values()
            ->toJson(JSON_UNESCAPED_UNICODE);
    }

    /* -----------------------------------------------------------------
     | 内部：展开与冲突预检
     | ----------------------------------------------------------------- */

    /**
     * 单个模板在给定周数内的展开结果
     *
     * @return array<int, array{start_at: Carbon, end_at: Carbon}>
     */
    private function expandTemplate(FixedSchedule $tpl, Carbon $start, int $weeks): array
    {
        $slots = [];
        $now = Carbon::now('Asia/Shanghai');

        for ($i = 0; $i < $weeks; $i++) {
            $day = $start->copy()->addWeeks($i)->addDays(max(0, $tpl->weekday - 1));

            if ($tpl->effective_from && $day->lt($tpl->effective_from->copy()->startOfDay())) {
                continue;
            }
            if ($tpl->effective_to && $day->gt($tpl->effective_to->copy()->endOfDay())) {
                continue;
            }

            [$sh, $sm] = $this->splitTime($tpl->start_time);
            [$eh, $em] = $this->splitTime($tpl->end_time);

            $startAt = $day->copy()->setTime($sh, $sm);
            $endAt = $day->copy()->setTime($eh, $em);

            // 跨零点（如 23:00-00:30）时结束时间落到次日
            if ($endAt->lte($startAt)) {
                $endAt->addDay();
            }

            if ($endAt->lte($now)) {
                continue;
            }

            $slots[] = ['start_at' => $startAt, 'end_at' => $endAt];
        }

        return $slots;
    }

    /**
     * 已存在的固定场记录键集合：`模板ID|Y-m-d H:i`
     *
     * @return array<string, true>
     */
    private function existingKeys(string $orgCode, Carbon $from, Carbon $to): array
    {
        $keys = [];

        BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->whereNotNull('fixed_schedule_id')
            ->where('start_at', '>=', $from)
            ->where('start_at', '<', $to)
            ->select(['id', 'fixed_schedule_id', 'start_at'])
            ->chunkById(1000, function ($rows) use (&$keys) {
                foreach ($rows as $row) {
                    $keys[$row->fixed_schedule_id.'|'.Carbon::parse($row->start_at)->format('Y-m-d H:i')] = true;
                }
            }, 'id');

        return $keys;
    }

    /**
     * 已占用时段索引：日期 → 占用条目列表（非取消记录）
     *
     * @return array<string, array<int, array{venue: string, start: Carbon, end: Carbon, label: string}>>
     */
    private function occupancyIndex(string $orgCode, Carbon $from, Carbon $to): array
    {
        $index = [];

        BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->select(['id', 'venue', 'start_at', 'end_at', 'student_name', 'coach_name'])
            ->chunkById(1000, function ($rows) use (&$index) {
                foreach ($rows as $row) {
                    $this->registerOccupancy($index, [
                        'start_at' => Carbon::parse($row->start_at),
                        'end_at' => Carbon::parse($row->end_at),
                        'venue' => $row->venue,
                    ], $row->student_name.($row->coach_name ? '/'.$row->coach_name : ''));
                }
            }, 'id');

        return $index;
    }

    /**
     * 登记一条占用（供冲突预检使用）
     *
     * @param  array<string, array<int, array{slots: array<int, string>, start: Carbon, end: Carbon, label: string}>>  $index
     * @param  array{start_at: Carbon, end_at: Carbon, venue?: string}|array{start_at: Carbon, end_at: Carbon, venue: string}  $slot
     */
    private function registerOccupancy(array &$index, array $slot, string $label): void
    {
        $venue = (string) ($slot['venue'] ?? '');
        $day = $slot['start_at']->format('Y-m-d');

        $index[$day][] = [
            'venue' => $venue,
            'start' => $slot['start_at'],
            'end' => $slot['end_at'],
            'label' => $label,
        ];
    }

    /**
     * 冲突预检：同一天、场地互斥、且时间重叠时视为冲突
     *
     * 场地判定与 BookingService::checkConflict 保持一致：已占记录落在
     * 本次场地（含其所属整场）的占位集合里才算冲突，因此
     * 「1A + 1B」拼场可以同时约，整场「1」则与 1A/1B 都互斥。
     *
     * @param  array<string, array<int, array{venue: string, start: Carbon, end: Carbon, label: string}>>  $index
     * @param  array{start_at: Carbon, end_at: Carbon}  $slot
     */
    private function findConflict(array $index, array $slot, string $venue = ''): ?string
    {
        $venue = $venue !== '' ? $venue : (string) ($slot['venue'] ?? '');
        $day = $slot['start_at']->format('Y-m-d');
        $slots = $this->bookings->venueSlots($venue);

        foreach ($index[$day] ?? [] as $occupied) {
            if (! in_array($occupied['venue'], $slots, true)) {
                continue;
            }

            if ($occupied['start']->lt($slot['end_at']) && $occupied['end']->gt($slot['start_at'])) {
                return $occupied['label'].' '.$occupied['start']->format('H:i').'-'.$occupied['end']->format('H:i')
                    .'（'.$occupied['venue'].'）';
            }
        }

        return null;
    }

    /**
     * 时间归一化：'7.30' / '7:30' / '730' → '07:30'
     */
    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if ($time === '') {
            return '';
        }

        $time = str_replace(['：', '.', '。'], ':', $time);

        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{3,4})$/', $time, $m)) {
            $digits = str_pad($m[1], 4, '0', STR_PAD_LEFT);

            return substr($digits, 0, 2).':'.substr($digits, 2, 2);
        }

        if (preg_match('/^(\d{1,2})$/', $time, $m)) {
            return sprintf('%02d:00', (int) $m[1]);
        }

        return $time;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function splitTime(string $time): array
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return [(int) $h, (int) $m];
    }

    /**
     * 固定场记录备注：用途场标记 + 模板备注
     */
    private function buildRemark(FixedSchedule $tpl): string
    {
        $parts = [];

        if (! $tpl->is_student) {
            $parts[] = '用途场';
        }
        if ($tpl->exclusive) {
            $parts[] = '不拼';
        }
        if ($tpl->remark) {
            $parts[] = $tpl->remark;
        }

        return implode(' · ', $parts);
    }
}
