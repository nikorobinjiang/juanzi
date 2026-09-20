<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\ReminderLog;
use App\Models\Scopes\OrganizationScope;
use App\Models\WechatBinding;
use App\Support\BookingWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 明日上课提醒编排
 *
 * 两条流水线（学员 / 教练）只在「取哪些约课」「按谁的姓名匹配」「渲染什么模板」三处分叉，
 * 查询、匹配、去重、发送、写日志全部共用一条路径。
 *
 * 提醒 Web ：本类不碰任何 HTTP 细节，微信交互全部委托给 WechatService，
 * 单测时把 WechatService 换成 Fake 即可完整跑通推送流程。
 */
class LessonReminderService
{
    /** 统计键：命中人数 / 已发 / 失败 / 跳过 */
    private const KEYS = ['targets', 'sent', 'failed', 'skipped'];

    /** 预览模式下积累的文本输出，交给命令统一打印 */
    private array $messages = [];

    public function __construct(private WechatService $wechat)
    {
    }

    /**
     * 本次运行的输出明细（--dry-run 预览内容、跳过原因等）
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /* -----------------------------------------------------------------
     | 时间窗口
     | ----------------------------------------------------------------- */

    /**
     * 次日窗口 [start, end)（Asia/Shanghai，左闭右开，与 BookingWindow 约定一致）
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function tomorrowWindow(?Carbon $day = null): array
    {
        $date = $this->dateOf($day);

        return [$date->copy(), $date->copy()->addDay()];
    }

    /**
     * 归一化日期：不传表示「明天」；传了可以是日期字符串或 Carbon
     */
    public function dateOf(mixed $day = null): Carbon
    {
        if ($day instanceof Carbon) {
            return $day->copy()->setTimezone(BookingWindow::TIMEZONE)->startOfDay();
        }

        if (is_string($day) && trim($day) !== '') {
            return Carbon::parse(trim($day), BookingWindow::TIMEZONE)->startOfDay();
        }

        return BookingWindow::now()->addDay()->startOfDay();
    }

    /* -----------------------------------------------------------------
     | 查询
     | ----------------------------------------------------------------- */

    /**
     * 指定日期的未取消约课，按机构分组
     *
     * CLI 与绑定页都没有 auth('web')，这里显式去掉机构作用域而不是依赖它自动失效。
     *
     * @param  string  $orgCode  机构 code，空表示全部机构
     * @return Collection<string, EloquentCollection<int, BookingRecord>>
     */
    public function bookingsByOrganization(Carbon $date, string $orgCode = ''): Collection
    {
        [$start, $end] = [$date->copy(), $date->copy()->addDay()];

        return BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('status', BookingRecord::STATUS_BOOKED)
            ->where('start_at', '>=', $start)
            ->where('start_at', '<', $end)
            ->when($orgCode !== '', fn ($query) => $query->where('organization_code', $orgCode))
            ->orderBy('start_at')
            ->get()
            ->groupBy(fn (BookingRecord $booking) => (string) $booking->organization_code);
    }

    /**
     * 某机构下启用中的绑定（按身份）
     *
     * @return EloquentCollection<int, WechatBinding>
     */
    public function bindingsFor(string $orgCode, string $type): EloquentCollection
    {
        return WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('target_type', $type)
            ->where('enabled', true)
            ->get();
    }

    /**
     * 某个微信用户在该机构下绑定的身份对应的明日课程
     *
     * @return array<int, array{start_at: string, end_at: string, venue: string, coach_name: string, student_name: string}>
     */
    public function lessonsForOpenid(string $orgCode, string $openid, string $type, ?Carbon $day = null): array
    {
        $date = $this->dateOf($day);
        $bindings = WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('openid', $openid)
            ->where('target_type', $type)
            ->where('enabled', true)
            ->get();

        if ($bindings->isEmpty()) {
            return [];
        }

        $bookings = $this->bookingsByOrganization($date, $orgCode)->get($orgCode, collect());

        return $bookings
            ->filter(fn (BookingRecord $booking) => $this->matchBindings($booking, $bindings)->isNotEmpty())
            ->sortBy('start_at')
            ->values()
            ->map(fn (BookingRecord $booking) => [
                'start_at' => $this->clock($booking->start_at),
                'end_at' => $this->clock($booking->end_at),
                'venue' => (string) $booking->venue,
                'coach_name' => (string) $booking->coach_name,
                'student_name' => (string) $booking->student_name,
            ])
            ->all();
    }

    /* -----------------------------------------------------------------
     | 流水线
     | ----------------------------------------------------------------- */

    /**
     * 学员提醒：每个微信用户一条汇总卡片
     *
     * @return array{targets: int, sent: int, failed: int, skipped: int}
     */
    public function remindStudents(Carbon $date, string $orgCode = '', array $options = []): array
    {
        return $this->run(
            WechatBinding::TYPE_STUDENT,
            $date,
            $orgCode,
            $options,
            fn (EloquentCollection $lessons, Collection $names) => $this->buildStudentData($lessons, $names, $date),
            'templates.student'
        );
    }

    /**
     * 教练提醒：每个微信用户一条汇总卡片（教练一天可能排 8~10 节课，绝不拆成多条）
     *
     * @return array{targets: int, sent: int, failed: int, skipped: int}
     */
    public function remindCoaches(Carbon $date, string $orgCode = '', array $options = []): array
    {
        return $this->run(
            WechatBinding::TYPE_COACH,
            $date,
            $orgCode,
            $options,
            fn (EloquentCollection $lessons, Collection $names) => $this->buildCoachData($lessons, $names, $date),
            'templates.coach'
        );
    }

    /**
     * 两条流水线共用的执行路径
     *
     * @param  callable(EloquentCollection<int, BookingRecord>, Collection<int, string>): array  $render
     * @return array{targets: int, sent: int, failed: int, skipped: int}
     */
    private function run(string $type, Carbon $date, string $orgCode, array $options, callable $render, string $configKey): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $perLesson = (bool) ($options['per_lesson'] ?? false) && $type === WechatBinding::TYPE_STUDENT;

        $templateId = $this->templateId($configKey, $type);
        $stats = array_fill_keys(self::KEYS, 0);
        $this->messages = [];

        if ($templateId === '') {
            $this->messages[] = "[{$type}] 未配置模板 ID，跳过本轮推送";
            Log::warning('上课提醒跳过：缺少模板 ID', ['target_type' => $type, 'remind_date' => $date->toDateString()]);

            return $stats;
        }

        foreach ($this->bookingsByOrganization($date, $orgCode) as $groupOrgCode => $bookings) {
            $bindings = $this->bindingsFor((string) $groupOrgCode, $type);

            if ($bindings->isEmpty()) {
                continue;
            }

            // openid -> 该用户当天的课程集合（同一家长管多个孩子时天然合并成一条）
            $grouped = $this->groupByOpenid($bookings, $bindings, $type);

            foreach ($grouped as $openid => $pair) {
                /** @var EloquentCollection<int, BookingRecord> $lessons */
                $lessons = $pair['lessons'];
                /** @var Collection<int, string> $names */
                $names = $pair['names'];
                /** @var EloquentCollection<int, WechatBinding> $hits */
                $hits = $pair['bindings'];

                $stats['targets']++;

                // 汇总模式一条消息；--per-lesson 时每节课一条（仅学员端）
                $scopes = $perLesson
                    ? $lessons->map(fn (BookingRecord $b) => [(string) $b->id, new EloquentCollection([$b])])->all()
                    : [[ReminderLog::SCOPE_SUMMARY, $lessons]];

                foreach ($scopes as [$scopeKey, $scopeLessons]) {
                    if (! $force && $this->alreadySent((string) $groupOrgCode, $openid, $type, $date, (string) $scopeKey)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $this->dispatch(
                        (string) $groupOrgCode,
                        (string) $openid,
                        $type,
                        $names,
                        $hits,
                        $date,
                        (string) $scopeKey,
                        $scopeLessons,
                        $templateId,
                        $render($scopeLessons, $names),
                        $dryRun,
                        $stats
                    );
                }
            }
        }

        return $stats;
    }

    /**
     * 单条模板消息的发送与留痕
     *
     * @param  EloquentCollection<int, BookingRecord>  $lessons
     * @param  Collection<int, string>  $names
     * @param  EloquentCollection<int, WechatBinding>  $bindings
     * @param  array<string, array{value: string, color: string}>  $data
     * @param  array{targets: int, sent: int, failed: int, skipped: int}  $stats
     */
    private function dispatch(
        string $orgCode,
        string $openid,
        string $type,
        Collection $names,
        EloquentCollection $bindings,
        Carbon $date,
        string $scopeKey,
        EloquentCollection $lessons,
        string $templateId,
        array $data,
        bool $dryRun,
        array &$stats
    ): void {
        $targetName = $names->implode('、');
        $bookingIds = $lessons->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($dryRun) {
            $stats['skipped']++;
            $this->messages[] = '  · '.$orgCode.' / '.$targetName.'：'.$this->digest($data);

            return;
        }

        $result = $this->wechat->sendTemplateMessage($openid, $templateId, $data, $this->templateUrl($type));

        $status = $result['ok'] ? ReminderLog::STATUS_SENT : ReminderLog::STATUS_FAILED;
        $stats[$result['ok'] ? 'sent' : 'failed']++;

        $this->writeLog([
            'organization_code' => $orgCode,
            'binding_id' => $bindings->first()?->id,
            'openid' => $openid,
            'target_type' => $type,
            'target_name' => mb_substr($targetName, 0, 50),
            'remind_date' => $date->toDateString(),
            'scope_key' => $scopeKey,
            'booking_ids' => $bookingIds,
            'payload' => $data,
            'status' => $status,
            'errcode' => $result['ok'] ? null : $result['errcode'],
            'error' => $result['ok'] ? null : mb_substr($result['errmsg'], 0, 255),
            'sent_at' => $result['ok'] ? BookingWindow::now() : null,
        ]);

        if ($result['ok'] || ! $this->wechat->isInvalidTarget($result['errcode'])) {
            return;
        }

        // 取关 / openid 失效：停用绑定，下次不再浪费一次发送名额
        WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->whereIn('id', $bindings->pluck('id')->all())
            ->update(['enabled' => false, 'last_failed_at' => BookingWindow::now()]);

        Log::warning('上课提醒接收人已失效，停用绑定', [
            'organization_code' => $orgCode,
            'target_type' => $type,
            'openid_suffix' => mb_substr($openid, -6),
            'errcode' => $result['errcode'],
        ]);
    }

    /* -----------------------------------------------------------------
     | 匹配与分组
     | ----------------------------------------------------------------- */

    /**
     * 约课记录 -> openid 分组
     *
     * 按 openid 而不是按姓名分组：同一个家长绑定了两个孩子时合并成一张卡片，体验比两条消息好。
     *
     * @param  EloquentCollection<int, BookingRecord>  $bookings
     * @param  EloquentCollection<int, WechatBinding>  $bindings
     * @return array<string, array{lessons: EloquentCollection<int, BookingRecord>, names: Collection<int, string>, bindings: EloquentCollection<int, WechatBinding>}>
     */
    private function groupByOpenid(EloquentCollection $bookings, EloquentCollection $bindings, string $type): array
    {
        $grouped = [];

        foreach ($bookings as $booking) {
            $hits = $this->matchBindings($booking, $bindings);

            foreach ($hits as $binding) {
                $openid = (string) $binding->openid;

                if (! isset($grouped[$openid])) {
                    $grouped[$openid] = ['lessons' => new EloquentCollection, 'names' => collect(), 'bindings' => new EloquentCollection];
                }

                if (! $grouped[$openid]['lessons']->contains(fn (BookingRecord $b) => $b->id === $booking->id)) {
                    $grouped[$openid]['lessons']->push($booking);
                }

                $name = $binding->name;

                if ($name !== '' && ! $grouped[$openid]['names']->contains($name)) {
                    $grouped[$openid]['names']->push($name);
                }

                if (! $grouped[$openid]['bindings']->contains(fn (WechatBinding $b) => $b->id === $binding->id)) {
                    $grouped[$openid]['bindings']->push($binding);
                }
            }
        }

        foreach ($grouped as $openid => $pair) {
            $grouped[$openid]['lessons'] = $pair['lessons']->sortBy('start_at')->values();
        }

        return $grouped;
    }

    /**
     * 约课记录命中了哪些绑定
     *
     * 学员名之间存在历史脏数据（"小明"、"小明同学"），沿用 Student 统计口径：先精确，再包含兜底；
     * 教练名写入前已经 CoachService::resolveCoachName() 归一，精确比对即可，避免误匹到同名前缀。
     *
     * @param  EloquentCollection<int, WechatBinding>  $bindings
     * @return EloquentCollection<int, WechatBinding>
     */
    private function matchBindings(BookingRecord $booking, EloquentCollection $bindings): EloquentCollection
    {
        $student = trim((string) $booking->student_name);
        $coach = trim((string) $booking->coach_name);

        return $bindings->filter(function (WechatBinding $binding) use ($student, $coach): bool {
            if ($binding->target_type === WechatBinding::TYPE_COACH) {
                return $coach !== '' && $coach === trim((string) $binding->coach_name);
            }

            $name = trim((string) $binding->student_name);

            if ($name === '') {
                return false;
            }

            return $student === $name || ($student !== '' && str_contains($student, $name));
        })->values();
    }

    /* -----------------------------------------------------------------
     | 卡片渲染
     | ----------------------------------------------------------------- */

    /**
     * 学员卡片数据
     *
     * @param  EloquentCollection<int, BookingRecord>  $lessons
     * @param  Collection<int, string>  $names
     * @return array<string, array{value: string, color: string}>
     */
    private function buildStudentData(EloquentCollection $lessons, Collection $names, Carbon $date): array
    {
        $times = $lessons->map(fn (BookingRecord $b) => $this->clock($b->start_at).'-'.$this->clock($b->end_at));
        $venues = $lessons->map(fn (BookingRecord $b) => trim((string) $b->venue))->filter()->unique()->values();
        $coaches = $lessons->map(fn (BookingRecord $b) => trim((string) $b->coach_name))->filter()->unique()->values();

        return $this->renderData('templates.student', [
            'keyword1' => $this->dateLabel($date),
            'keyword2' => $lessons->count() > 1 ? $this->joinList($times) : ($times->first() ?: ''),
            'keyword3' => $venues->isEmpty() ? '待定' : $venues->implode('、'),
            'keyword4' => $coaches->isEmpty() ? '待定' : $coaches->implode('、'),
        ]);
    }

    /**
     * 教练卡片数据
     *
     * @param  EloquentCollection<int, BookingRecord>  $lessons
     * @param  Collection<int, string>  $names
     * @return array<string, array{value: string, color: string}>
     */
    private function buildCoachData(EloquentCollection $lessons, Collection $names, Carbon $date): array
    {
        $first = $this->clock($lessons->first()?->start_at);
        $last = $this->clock($lessons->last()?->end_at);
        $venues = $lessons->map(fn (BookingRecord $b) => trim((string) $b->venue))->filter()->unique()->values();
        $students = $lessons->map(fn (BookingRecord $b) => trim((string) $b->student_name))->filter()->unique()->values();

        // 教练模板只有四个关键词，场地塞不进去，统一挂到备注行末尾
        $remark = trim((string) config('wechat.templates.coach.remark', ''));

        return $this->renderData('templates.coach', [
            'keyword1' => $this->dateLabel($date),
            'keyword2' => $first && $last ? $first.'-'.$last : '',
            'keyword3' => $lessons->count().' 节',
            'keyword4' => $students->isEmpty() ? '待定' : $this->joinList($students),
        ], trim($remark.' 场地：'.($venues->isEmpty() ? '待定' : $venues->implode('、'))));
    }

    /**
     * 按模板配置把「关键词名 => 值」渲染成微信要求的 data 结构
     *
     * @param  array<string, string>  $values
     * @return array<string, array{value: string, color: string}>
     */
    private function renderData(string $configKey, array $values, ?string $remark = null): array
    {
        $base = 'wechat.'.$configKey;
        $color = (string) config($base.'.color', '#173177');
        $first = (string) config($base.'.first', '');

        $mapping = (array) config($base.'.fields', []);

        $data = ['first' => ['value' => $first, 'color' => $color]];

        foreach ($mapping as $key => $label) {
            $value = $values[(string) $key] ?? '';

            // 空值也占位，保证卡片行数与模板关键词数量一致，否则微信会报 47003
            $data[(string) $key] = ['value' => $value === '' ? '—' : $value, 'color' => $color];
        }

        $data['remark'] = [
            'value' => $remark ?? (string) config($base.'.remark', ''),
            'color' => $color,
        ];

        return $data;
    }

    /* -----------------------------------------------------------------
     | 辅助
     | ----------------------------------------------------------------- */

    /**
     * 模板 ID：教练未单独配置时回退学员模板，避免上线后因为少配一个模板而整条流水线空转
     */
    private function templateId(string $configKey, string $type): string
    {
        $id = trim((string) config('wechat.'.$configKey.'.id'));

        if ($id === '' && $type === WechatBinding::TYPE_COACH) {
            $id = trim((string) config('wechat.templates.student.id'));
        }

        return $id;
    }

    private function templateUrl(string $type): string
    {
        $path = trim((string) config('wechat.templates.'.$type.'.url', ''));

        return $path === '' ? '' : url($path);
    }

    /**
     * 幂等判定：同一天同一范围已经发成功过就不再发
     */
    private function alreadySent(string $orgCode, string $openid, string $type, Carbon $date, string $scopeKey): bool
    {
        return ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('openid', $openid)
            ->where('target_type', $type)
            ->where('remind_date', $date->toDateString())
            ->where('scope_key', $scopeKey)
            ->where('status', ReminderLog::STATUS_SENT)
            ->exists();
    }

    /**
     * 写发送日志；唯一冲突在这里兜住，不影响其余接收人
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeLog(array $attributes): void
    {
        $keys = [
            'organization_code' => $attributes['organization_code'],
            'remind_date' => $attributes['remind_date'],
            'openid' => $attributes['openid'],
            'target_type' => $attributes['target_type'],
            'scope_key' => $attributes['scope_key'],
        ];

        try {
            ReminderLog::withoutGlobalScope(OrganizationScope::class)
                ->updateOrCreate($keys, $attributes);
        } catch (\Throwable $e) {
            Log::warning('上课提醒日志写入失败', [
                'organization_code' => $attributes['organization_code'],
                'target_type' => $attributes['target_type'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 列表拼接：超过上限折叠成「张三、李四 等 5 人」
     *
     * @param  Collection<int, string>  $items
     */
    private function joinList(Collection $items): string
    {
        $max = max(1, (int) config('wechat.remind.max_items', 4));
        $values = $items->values();

        if ($values->count() <= $max) {
            return $values->implode('、');
        }

        return $values->take($max)->implode('、').' 等 '.$values->count().' 项';
    }

    private function clock(?Carbon $at): string
    {
        if (! $at instanceof Carbon) {
            return '';
        }

        return $at->copy()->setTimezone(BookingWindow::TIMEZONE)->format('H:i');
    }

    private function dateLabel(Carbon $date): string
    {
        $week = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

        return $date->format('n月j日').' '.$week[$date->dayOfWeek];
    }

    /**
     * 预览模式下把卡片压成一行文本，便于 --dry-run 时肉眼核对
     *
     * @param  array<string, array{value: string, color: string}>  $data
     */
    private function digest(array $data): string
    {
        return collect($data)
            ->filter(fn ($item, $key) => $key !== 'first' && $key !== 'remark')
            ->map(fn ($item) => $item['value'])
            ->filter(fn ($value) => $value !== '')
            ->implode(' | ');
    }
}
