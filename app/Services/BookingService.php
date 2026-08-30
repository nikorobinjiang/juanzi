<?php

namespace App\Services;

use App\Models\BookingRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 约课核心业务：冲突检测、增删改、周分组
 */
class BookingService
{
    private array $venues;

    public function __construct()
    {
        $this->venues = (array) config('doubao.booking.venues', ['1A', '1B', '2A', '2B']);
    }

    /* -----------------------------------------------------------------
     | 创建
     | ----------------------------------------------------------------- */

    /**
     * 创建约课。自动检测场地冲突，未指定场地时自动分配空闲场地。
     *
     * @return array ['success' => bool, 'booking' => ?BookingRecord, 'conflict' => ?BookingRecord, 'message' => string]
     */
    public function create(array $data): array
    {
        $startAt = Carbon::parse($data['start_at']);
        $duration = (int) config('doubao.booking.duration_minutes', 60);
        $endAt = $startAt->copy()->addMinutes($duration);

        $venue = trim((string) ($data['venue'] ?? ''));
        $coach = trim((string) ($data['coach_name'] ?? ''));

        // 教练冲突：同一教练同一时间只能带一节课（与场地无关，先校验）
        if ($coach !== '') {
            $coachConflict = $this->checkCoachConflict($coach, $startAt, $endAt);
            if ($coachConflict) {
                return [
                    'success' => false,
                    'booking' => null,
                    'conflict' => $coachConflict,
                    'message' => '教练冲突：教练「'.$coach.'」在 '.$startAt->format('m月d日 H:i')
                        .' 已有课（'.$coachConflict->student_name.' · 场地 '.$coachConflict->venue.'），'
                        .'同一时间只能带一个学员，请换个时间。',
                ];
            }
        }

        if ($venue === '') {
            // 自动分配：挑第一个空闲场地
            foreach ($this->venues as $v) {
                if (! $this->checkConflict($v, $startAt, $endAt)) {
                    $venue = $v;
                    break;
                }
            }

            if ($venue === '') {
                return [
                    'success' => false,
                    'booking' => null,
                    'conflict' => null,
                    'message' => '抱歉，'.$startAt->format('m月d日 H:i').' 四个场地（1A/1B/2A/2B）都有约了，请换个时间。',
                ];
            }
        } else {
            $conflict = $this->checkConflict($venue, $startAt, $endAt);
            if ($conflict) {
                return [
                    'success' => false,
                    'booking' => null,
                    'conflict' => $conflict,
                    'message' => '场地冲突：'.$venue.' 场地在 '.$startAt->format('m月d日 H:i').' 已被占用（'
                        .$conflict->student_name.' / '.$conflict->coach_name.'），请换场地或改时间。',
                ];
            }
        }

        $booking = BookingRecord::create([
            'student_name' => trim((string) ($data['student_name'] ?? '')),
            'coach_name' => trim((string) ($data['coach_name'] ?? '')),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'venue' => $venue,
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => trim((string) ($data['remark'] ?? '')),
        ]);

        return [
            'success' => true,
            'booking' => $booking,
            'conflict' => null,
            'message' => '约课成功：'.$booking->student_name.'（教练 '.$booking->coach_name.'）'
                .$booking->start_at->format('m月d日 H:i').' 场地 '.$booking->venue,
        ];
    }

    /* -----------------------------------------------------------------
     | 修改
     | ----------------------------------------------------------------- */

    /**
     * 修改约课（时间/场地/学员/教练/备注）
     *
     * @param  int  $id
     */
    public function update(int $id, array $data): array
    {
        $booking = BookingRecord::find($id);
        if (! $booking) {
            return ['success' => false, 'booking' => null, 'message' => '找不到要修改的约课记录'];
        }

        // 计算修改后的目标值（未提供的字段沿用原值）
        $newStartAt = array_key_exists('start_at', $data) && $data['start_at']
            ? Carbon::parse($data['start_at'])
            : $booking->start_at;
        $newCoach = array_key_exists('coach_name', $data) && $data['coach_name']
            ? trim($data['coach_name'])
            : $booking->coach_name;
        $newVenue = array_key_exists('venue', $data) && $data['venue']
            ? trim($data['venue'])
            : $booking->venue;

        $duration = (int) config('doubao.booking.duration_minutes', 60);
        $newEndAt = $newStartAt->copy()->addMinutes($duration);

        $timeChanged = $newStartAt->ne($booking->start_at);
        $venueChanged = $newVenue !== $booking->venue;
        $coachChanged = $newCoach !== $booking->coach_name;

        // 时间或场地变化 → 场地冲突检测
        if ($timeChanged || $venueChanged) {
            $conflict = $this->checkConflict($newVenue, $newStartAt, $newEndAt, $booking->id);
            if ($conflict) {
                return [
                    'success' => false,
                    'booking' => $booking,
                    'message' => '修改失败，场地冲突：'.$newVenue.' 场地在 '.$newStartAt->format('m月d日 H:i')
                        .' 已被占用（'.$conflict->student_name.' / '.$conflict->coach_name.'）。',
                ];
            }
        }

        // 时间或教练变化 → 教练冲突检测
        if ($timeChanged || $coachChanged) {
            $conflict = $this->checkCoachConflict($newCoach, $newStartAt, $newEndAt, $booking->id);
            if ($conflict) {
                return [
                    'success' => false,
                    'booking' => $booking,
                    'message' => '修改失败，教练冲突：教练「'.$newCoach.'」在 '.$newStartAt->format('m月d日 H:i')
                        .' 已有课（'.$conflict->student_name.' · 场地 '.$conflict->venue.'），'
                        .'同一时间只能带一个学员。',
                ];
            }
        }

        $booking->start_at = $newStartAt;
        $booking->end_at = $newEndAt;
        $booking->venue = $newVenue;
        if ($coachChanged) {
            $booking->coach_name = $newCoach;
        }
        if (array_key_exists('student_name', $data) && $data['student_name']) {
            $booking->student_name = trim($data['student_name']);
        }
        if (array_key_exists('remark', $data) && $data['remark'] !== null) {
            $booking->remark = trim($data['remark']);
        }

        $booking->save();

        return [
            'success' => true,
            'booking' => $booking,
            'message' => '修改成功：'.$booking->student_name.'（教练 '.$booking->coach_name.'）'
                .$booking->start_at->format('m月d日 H:i').' 场地 '.$booking->venue,
        ];
    }

    /* -----------------------------------------------------------------
     | 删除
     | ----------------------------------------------------------------- */

    public function delete(int $id): array
    {
        $booking = BookingRecord::find($id);
        if (! $booking) {
            return ['success' => false, 'message' => '找不到要删除的约课记录'];
        }

        $info = $booking->student_name.'（'.$booking->coach_name.'）'.$booking->start_at->format('m月d日 H:i').' '.$booking->venue;
        $booking->delete();

        return ['success' => true, 'message' => '已删除约课：'.$info];
    }

    /* -----------------------------------------------------------------
     | 完成课程
     | ----------------------------------------------------------------- */

    public function complete(int $id): array
    {
        $booking = BookingRecord::find($id);
        if (! $booking) {
            return ['success' => false, 'message' => '找不到对应的约课记录'];
        }

        $booking->status = BookingRecord::STATUS_COMPLETED;
        $booking->save();

        return [
            'success' => true,
            'booking' => $booking,
            'message' => '已标记完成：'.$booking->student_name.'（'.$booking->coach_name.'）'
                .$booking->start_at->format('m月d日 H:i').' '.$booking->venue,
        ];
    }

    /* -----------------------------------------------------------------
     | 冲突检测
     | ----------------------------------------------------------------- */

    /**
     * 检测某场地在时间段内是否冲突（跳过 ignoreId）
     */
    public function checkConflict(string $venue, Carbon $startAt, Carbon $endAt, ?int $ignoreId = null): ?BookingRecord
    {
        return BookingRecord::where('venue', $venue)
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('id', '!=', $ignoreId ?? 0)
            ->where(function ($q) use ($startAt, $endAt) {
                $q->whereBetween('start_at', [$startAt, $endAt->copy()->subSecond()])
                    ->orWhereBetween('end_at', [$startAt->copy()->addSecond(), $endAt])
                    ->orWhere(function ($q2) use ($startAt, $endAt) {
                        $q2->where('start_at', '<', $startAt)->where('end_at', '>', $endAt);
                    });
            })
            ->first();
    }

    /**
     * 检测某教练在时间段内是否冲突（同一教练同一时间只能带一节课，跳过 ignoreId）
     */
    public function checkCoachConflict(string $coach, Carbon $startAt, Carbon $endAt, ?int $ignoreId = null): ?BookingRecord
    {
        return BookingRecord::where('coach_name', 'like', '%'.$coach.'%')
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('id', '!=', $ignoreId ?? 0)
            ->where(function ($q) use ($startAt, $endAt) {
                $q->whereBetween('start_at', [$startAt, $endAt->copy()->subSecond()])
                    ->orWhereBetween('end_at', [$startAt->copy()->addSecond(), $endAt])
                    ->orWhere(function ($q2) use ($startAt, $endAt) {
                        $q2->where('start_at', '<', $startAt)->where('end_at', '>', $endAt);
                    });
            })
            ->first();
    }

    /* -----------------------------------------------------------------
     | 查询
     | ----------------------------------------------------------------- */

    public function all(): Collection
    {
        return BookingRecord::orderBy('start_at')->orderBy('venue')->get();
    }

    /* -----------------------------------------------------------------
     | 本地精确查询（统计 / 最近课程 / 排课 / 空闲时段）
     | ----------------------------------------------------------------- */

    /**
     * 统计某学员/教练已完成课程数（仅 status=completed）
     */
    public function countCompletedLessons(string $student = '', string $coach = ''): int
    {
        return $this->scopedQuery($student, $coach)
            ->where('status', BookingRecord::STATUS_COMPLETED)
            ->count();
    }

    /**
     * 最近一次已完成课程
     */
    public function lastLesson(string $student = '', string $coach = ''): ?BookingRecord
    {
        return $this->scopedQuery($student, $coach)
            ->where('status', BookingRecord::STATUS_COMPLETED)
            ->orderByDesc('start_at')
            ->first();
    }

    /**
     * 下一次课（已预约且未开始）
     */
    public function nextLesson(string $student = '', string $coach = ''): ?BookingRecord
    {
        return $this->scopedQuery($student, $coach)
            ->where('status', BookingRecord::STATUS_BOOKED)
            ->where('start_at', '>=', Carbon::now('Asia/Shanghai'))
            ->orderBy('start_at')
            ->first();
    }

    /**
     * 某学员/教练在时间段内的排课（默认未来 30 天，不含已取消）
     */
    public function schedule(string $student = '', string $coach = '', ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->scopedQuery($student, $coach)
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->when($from, fn ($q) => $q->where('start_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('start_at', '<', $to->copy()->addDay()))
            ->orderBy('start_at')
            ->get();
    }

    /**
     * 教练空闲时段：from 到 to（含）按天输出营业时段内的空闲段
     *
     * @return array<int, array{date: string, slots: array<int, string>}>
     */
    public function coachAvailability(string $coach, Carbon $from, Carbon $to): array
    {
        return $this->buildAvailability($from, $to, BookingRecord::where('coach_name', 'like', '%'.$coach.'%')
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('start_at', '<', $to->copy()->addDay())
            ->where('end_at', '>', $from)
            ->get());
    }

    /**
     * 场地空闲时段：from 到 to（含）按天输出营业时段内的空闲段
     *
     * @return array<int, array{date: string, slots: array<int, string>}>
     */
    public function venueAvailability(string $venue, Carbon $from, Carbon $to): array
    {
        return $this->buildAvailability($from, $to, BookingRecord::where('venue', $venue)
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('start_at', '<', $to->copy()->addDay())
            ->where('end_at', '>', $from)
            ->get());
    }

    /**
     * 生成空闲时段列表：每天按营业时间（hours.start ~ hours.end，每 duration 一段），
     * 与给定已占用记录时间段重叠的时段视为忙碌。
     *
     * @param  Collection<int, BookingRecord>  $booked
     * @return array<int, array{date: string, slots: array<int, string>}>
     */
    private function buildAvailability(Carbon $from, Carbon $to, Collection $booked): array
    {
        $startHour = (int) config('doubao.booking.hours.start', 8);
        $endHour = (int) config('doubao.booking.hours.end', 22);
        $duration = max(60, (int) config('doubao.booking.duration_minutes', 60));
        $stepHours = max(1, (int) round($duration / 60));

        $result = [];
        $day = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            $slots = [];

            for ($hour = $startHour; $hour < $endHour; $hour += $stepHours) {
                $slotStart = $day->copy()->setTime($hour, 0);
                $slotEnd = $slotStart->copy()->addMinutes($duration);

                $busy = $booked->contains(function (BookingRecord $b) use ($slotStart, $slotEnd) {
                    return $b->start_at->lt($slotEnd) && $b->end_at->gt($slotStart);
                });

                if (! $busy) {
                    $slots[] = $slotStart->format('H:i').'-'.$slotEnd->format('H:i');
                }
            }

            $result[] = ['date' => $day->format('Y-m-d'), 'slots' => $slots];
            $day->addDay();
        }

        return $result;
    }

    /**
     * 按学员/教练名模糊过滤的基础查询（任一为空则不过滤该维度）
     */
    private function scopedQuery(string $student = '', string $coach = '')
    {
        $query = BookingRecord::query();

        if ($student !== '') {
            $query->where('student_name', 'like', '%'.$student.'%');
        }
        if ($coach !== '') {
            $query->where('coach_name', 'like', '%'.$coach.'%');
        }

        return $query;
    }

    /**
     * 按"周"分组（周一为一周开始），返回：
     * [
     *   ['week_start' => '2026-08-24', 'week_end' => '2026-08-30', 'label' => '8月24日-8月30日', 'items' => [...]],
     * ]
     */
    public function weekly(): Collection
    {
        $grouped = collect();

        $this->all()->each(function (BookingRecord $booking) use ($grouped) {
            $weekStart = $booking->start_at->copy()->startOfWeek(Carbon::MONDAY);
            $key = $weekStart->format('Y-m-d');

            if (! $grouped->has($key)) {
                $grouped->put($key, [
                    'week_start' => $weekStart->format('Y-m-d'),
                    'week_end' => $weekStart->copy()->addDays(6)->format('Y-m-d'),
                    'label' => $weekStart->format('n月j日').'-'.$weekStart->copy()->addDays(6)->format('n月j日'),
                    'items' => collect(),
                ]);
            }

            $week = $grouped->get($key);
            $week['items']->push($booking);
            $grouped->put($key, $week);
        });

        // 按周开始时间排序
        return $grouped
            ->sortBy(fn ($week) => $week['week_start'])
            ->values()
            ->map(function ($week) {
                $week['items'] = $week['items']
                    ->sortBy(fn (BookingRecord $b) => $b->start_at->format('Y-m-d H:i'));
                return $week;
            })
            ->values();
    }

    /**
     * 按"周"分组，返回前端展示所需的结构（含 count、已格式化时间）
     */
    public function weeklyForApi(): Collection
    {
        return $this->weekly()->map(function (array $week) {
            return [
                'week_start' => $week['week_start'],
                'week_end' => $week['week_end'],
                'label' => $week['label'],
                'count' => $week['items']->count(),
                'items' => $week['items']->map(fn (BookingRecord $b) => [
                    'id' => $b->id,
                    'student_name' => $b->student_name,
                    'coach_name' => $b->coach_name,
                    'start_at' => $b->start_at->format('m-d H:i'),
                    'venue' => $b->venue,
                    'status' => $b->status,
                ])->values(),
            ];
        })->values();
    }

    /**
     * 输出给豆包的约课 JSON（仅未取消记录）
     */
    public function toJsonForAI(): string
    {
        // 只取最近的记录：取消/修改都是针对近期课程，全量 JSON 会让豆包 prompt 过大、响应变慢甚至超时
        return $this->all()
            ->reject(fn (BookingRecord $b) => $b->status === BookingRecord::STATUS_CANCELLED)
            ->sortByDesc('start_at')
            ->take(100)
            ->sortBy('start_at')
            ->map(function (BookingRecord $b) {
                return [
                    'id' => $b->id,
                    '学员' => $b->student_name,
                    '教练' => $b->coach_name,
                    '时间' => $b->start_at->format('Y-m-d H:i'),
                    '场地' => $b->venue,
                    '状态' => $b->status_label,
                    '备注' => $b->remark,
                ];
            })
            ->values()
            ->toJson(JSON_UNESCAPED_UNICODE);
    }

    /**
     * 根据用户提供的定位信息定位目标记录，并校验信息是否足够明确
     *
     * 定位信息：target_id / student_name / coach_name / start_at
     * - 未提供任何定位信息 → need_info=true，由调用方提示用户补全
     * - 多条匹配 → need_info=true，附候选项，请用户补充更具体的信息
     * - 唯一命中 → success=true
     *
     * @return array{
     *     success: bool,
     *     need_info: bool,
     *     booking: ?BookingRecord,
     *     candidates: array,
     *     message: string,
     * }
     */
    public function locateTarget(array $data): array
    {
        $targetId = (int) ($data['target_id'] ?? 0);
        $student = trim((string) ($data['student_name'] ?? ''));
        $coach = trim((string) ($data['coach_name'] ?? ''));
        $startAtRaw = trim((string) ($data['start_at'] ?? ''));

        // 有明确 id 直接命中
        if ($targetId) {
            $booking = BookingRecord::find($targetId);

            return $booking
                ? ['success' => true, 'need_info' => false, 'booking' => $booking, 'candidates' => [], 'message' => '']
                : ['success' => false, 'need_info' => false, 'booking' => null, 'candidates' => [], 'message' => '找不到对应的约课记录，可能已经被删除了。'];
        }

        // 没有任何定位信息 → 提示补全
        if ($student === '' && $coach === '' && $startAtRaw === '') {
            return [
                'success' => false,
                'need_info' => true,
                'booking' => null,
                'candidates' => [],
                'message' => '请告诉我是哪位学员、什么时间的课，我再帮你处理。',
            ];
        }

        $query = BookingRecord::query()
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED);

        if ($student !== '') {
            $query->where('student_name', 'like', '%'.$student.'%');
        }
        if ($coach !== '') {
            $query->where('coach_name', 'like', '%'.$coach.'%');
        }
        if ($startAtRaw !== '') {
            try {
                $query->where('start_at', 'like', '%'.Carbon::parse($startAtRaw)->format('Y-m-d H:i').'%');
            } catch (\Throwable $e) {
                // 时间无法解析时忽略该条件，避免查询报错
            }
        }

        $candidates = $query->orderBy('start_at')->orderBy('venue')->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => false,
                'need_info' => false,
                'booking' => null,
                'candidates' => [],
                'message' => $this->locateNotFoundMessage($student, $coach, $startAtRaw),
            ];
        }

        if ($candidates->count() === 1) {
            return ['success' => true, 'need_info' => false, 'booking' => $candidates->first(), 'candidates' => [], 'message' => ''];
        }

        // 多条匹配 → 列出候选项，请用户补充信息
        $list = $candidates
            ->map(fn (BookingRecord $b) => '· '.$b->student_name.'（'.$b->coach_name.'）'.$b->start_at->format('n月j日 H:i').' · '.$b->venue)
            ->implode("\n");

        return [
            'success' => false,
            'need_info' => true,
            'booking' => null,
            'candidates' => $candidates->all(),
            'message' => '找到了几条记录，请再告诉我更具体的信息（比如上课时间）：'."\n".$list,
        ];
    }

    private function locateNotFoundMessage(string $student, string $coach, string $startAtRaw): string
    {
        $parts = [];

        if ($student !== '') {
            $parts[] = '学员「'.$student.'」';
        }
        if ($coach !== '') {
            $parts[] = '教练「'.$coach.'」';
        }
        if ($startAtRaw !== '') {
            try {
                $parts[] = Carbon::parse($startAtRaw)->format('n月j日 H:i');
            } catch (\Throwable $e) {
                $parts[] = '该时间段';
            }
        }

        return $parts ? '没有找到'.implode('、', $parts).'的约课记录。' : '没有找到匹配的约课记录。';
    }
}
