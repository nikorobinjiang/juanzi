<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\MembershipCard;
use App\Models\MembershipUsage;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRM 写入口：聊天自然语言驱动的办卡 / 安排课时 / 报备会员消耗
 *
 * - createCard     ：给会员办月卡/年卡/次卡（同名会员允许多张卡）
 * - arrangeLessons ：给一个或多个学员建档 + 累加总课时 + 更新当前教练
 * - useCard        ：会员到店报备一次（次卡扣次数；月/年卡记到店次数）
 *
 * 机构隔离走模型全局 Scope + creating 钩子（web 请求下自动填充）。
 */
class CrmService
{
    /* -----------------------------------------------------------------
     | 办卡
     | ----------------------------------------------------------------- */

    public function createCard(array $data): string
    {
        $name = trim((string) ($data['member_name'] ?? ''));
        if ($name === '') {
            return '好的，要办卡的是哪位会员呢？请告诉我姓名，例如：给钱多多办20次游泳卡。';
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $type = strtolower(trim((string) ($data['card_type'] ?? '')));
        $total = (int) ($data['total_count'] ?? 0);

        if (! array_key_exists($type, MembershipCard::TYPE_LABELS)) {
            // 模型没有解析出类型时：给了次数按次卡，否则需要澄清
            if ($total > 0) {
                $type = MembershipCard::TYPE_VISITS;
            } else {
                return '没看明白要办哪种卡，请说明：月卡 / 年卡 / 次卡（N次）。例如：给'.$name.'办20次游泳卡。';
            }
        }

        if ($type === MembershipCard::TYPE_VISITS && $total < 1) {
            return '次卡需要说明次数哦，例如：给'.$name.'办20次游泳卡。';
        }

        [$startAt, $endAt] = $this->resolvePeriod($data, $type);

        $card = DB::transaction(function () use ($name, $phone, $type, $total, $startAt, $endAt) {
            return MembershipCard::create([
                'organization_code' => $this->orgCode(),
                'member_name' => $name,
                'phone' => $phone ?: null,
                'card_type' => $type,
                'total_count' => $type === MembershipCard::TYPE_VISITS ? $total : null,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'used_count' => 0,
                'note' => '聊天办卡',
            ]);
        });

        return $this->formatCardResult($name, $card).$this->activeCardNote($name, $card->id);
    }

    /* -----------------------------------------------------------------
     | 安排课时 / 分配教练
     | ----------------------------------------------------------------- */

    public function arrangeLessons(array $data): string
    {
        $names = $this->studentNames($data);
        if (empty($names)) {
            return '好的，要给哪位学员安排课时呢？例如：给学员A和B安排王教练，10次课。';
        }

        $coach = trim((string) ($data['coach_name'] ?? ''));
        $count = max(0, (int) ($data['lesson_count'] ?? 0));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($coach === '' && $count < 1) {
            return '请告诉我要安排的教练和课时数，例如：给'.$names[0].'安排王教练，10次课。';
        }

        $results = [];

        DB::transaction(function () use ($names, $coach, $count, $phone, &$results) {
            foreach ($names as $index => $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }

                /** @var Student $student */
                $student = Student::firstOrNew(['name' => $name]);

                if (! $student->exists) {
                    $student->organization_code = $this->orgCode();

                    if ($index === 0 && $phone !== '') {
                        $student->phone = $phone;
                    }
                }

                if ($coach !== '') {
                    $student->coach_name = $coach;
                }

                if ($count > 0) {
                    $student->lessons_total = (int) $student->lessons_total + $count;
                }

                $student->save();
                $results[] = [
                    'name' => $name,
                    'total' => (int) $student->lessons_total,
                    'coach' => (string) $student->coach_name,
                    'has_phone' => (string) $student->phone !== '',
                ];
            }
        });

        if (empty($results)) {
            return '没有识别到有效的学员姓名。';
        }

        $summary = collect($results)
            ->map(fn (array $r) => $r['name'].'（累计 '.$r['total'].' 节'.($r['coach'] !== '' ? ' · '.$r['coach'] : '').'）')
            ->implode('、');

        $action = $count > 0
            ? '已为学员 '.$summary.'，每人新增 '.$count.' 节课时'
            : '已为学员 '.$summary.' 设置教练';

        $noPhone = collect($results)->filter(fn (array $r) => ! $r['has_phone'])->isNotEmpty();

        return $action.'。'.($noPhone ? '（如需补充手机号，说"'.collect($names)->first().' 手机号13xxxxxxxxx"即可）' : '');
    }

    /* -----------------------------------------------------------------
     | 报备会员消耗
     | ----------------------------------------------------------------- */

    public function useCard(array $data): string
    {
        $name = trim((string) ($data['member_name'] ?? ''));
        if ($name === '') {
            return '好的，哪位会员到店/使用了呢？例如：钱多多今天用了一次。';
        }

        $cards = MembershipCard::where('member_name', $name)
            ->orderByDesc('start_at')
            ->get();

        if ($cards->isEmpty()) {
            return '没有查到会员 '.$name.' 的会员卡。如需办卡，可以说：给'.$name.'办20次游泳卡。';
        }

        // 优先扣减进行中的次卡
        $target = $cards->first(fn (MembershipCard $c) =>
            $c->card_type === MembershipCard::TYPE_VISITS && ! $c->isExpired() && $c->remaining_count > 0);

        // 其次记到店次数：进行中的月卡/年卡
        if (! $target) {
            $target = $cards->first(fn (MembershipCard $c) =>
                $c->card_type !== MembershipCard::TYPE_VISITS && ! $c->isExpired());
        }

        if (! $target) {
            $expired = $cards->contains(fn (MembershipCard $c) => $c->isExpired());

            return $expired
                ? $name.' 的会员卡都已过期/用完了，需要先办新卡哦（如：给'.$name.'办月卡）。'
                : $name.' 的次卡次数已用完，需要先办新卡哦。';
        }

        $usedAt = $this->tryParseDateTime($data['use_time'] ?? null) ?? now('Asia/Shanghai');

        DB::transaction(function () use ($target, $usedAt) {
            $target->increment('used_count');
            MembershipUsage::create([
                'organization_code' => $this->orgCode(),
                'card_id' => $target->id,
                'used_at' => $usedAt,
                'note' => '聊天报备到店',
            ]);
        });

        return $this->formatCardResult($name, $target->refresh(), $usedAt);
    }

    /* -----------------------------------------------------------------
    | 学员手机号
    | ----------------------------------------------------------------- */

    /**
     * 登记 / 修改学员手机号（聊天里说「小明手机号138xxxxxxxx」）
     *
     * 与 arrangeLessons 不同：这里只维护手机号，不建课时、不改教练；
     * 学员还没有档案时顺手建档（lessons_total 保持 0）。
     *
     * @param  array  $data  student_name + phone
     */
    public function updateStudentPhone(array $data): string
    {
        $name = trim((string) ($data['student_name'] ?? ''));
        if ($name === '') {
            return '好的，要给哪位学员登记手机号呢？例如：小明 手机号13800000000';
        }

        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            return '没识别出手机号呢，格式像：'.$name.' 手机号13800000000';
        }

        /** @var Student $student */
        $student = Student::firstOrNew(['name' => $name]);
        $created = ! $student->exists;

        if ($created) {
            $student->organization_code = $this->orgCode();
            $student->phone = $phone;
            $student->save();

            return '已为学员 '.$name.' 建档并登记手机号 '.$phone.'。';
        }

        $old = (string) $student->phone;

        if ($old === $phone) {
            return '学员 '.$name.' 的手机号本来就是 '.$phone.'，没有变化。';
        }

        $student->phone = $phone;
        $student->save();

        return $old === ''
            ? '已登记学员 '.$name.' 的手机号：'.$phone.'。'
            : '已把学员 '.$name.' 的手机号从 '.$old.' 改为 '.$phone.'。';
    }

    /* -----------------------------------------------------------------
    | 结果文案
    | ----------------------------------------------------------------- */

    private function formatCardResult(string $name, MembershipCard $card, ?Carbon $usedAt = null): string
    {
        $label = $card->type_label;

        if ($card->card_type === MembershipCard::TYPE_VISITS) {
            if ($usedAt) {
                return $name.' 已使用 1 次（'.$usedAt->format('n月j日 H:i').'），'.$label.'剩余 '.$card->remaining_count.' 次。';
            }

            return '已为会员 '.$name.' 办好 '.$card->total_count.' 次'.$label.'：已用 0 次，剩余 '.$card->remaining_count.' 次。';
        }

        $start = $card->start_at?->format('Y-m-d');
        $end = $card->end_at?->format('Y-m-d');
        $days = $card->remaining_days;

        if ($usedAt) {
            $tail = $days > 0 ? '剩余 '.$days.' 天' : '该卡已过期，建议办理新卡';

            return '已记录 '.$name.' 到店 1 次（'.$usedAt->format('n月j日 H:i').'）。'
                .$label.'有效期：'.($start && $end ? $start.' ~ '.$end.'，' : '').$tail.'。';
        }

        $period = $start && $end ? $start.' ~ '.$end : '即时起';
        $tail = $days > 0 ? '，剩余 '.$days.' 天' : '';

        return '已为会员 '.$name.' 办好'.$label.'（'.$period.'）'.$tail.'。';
    }

    /* -----------------------------------------------------------------
     | AI 上下文
     | ----------------------------------------------------------------- */

    /**
     * 当前机构学员/会员/教练名单 JSON，作为豆包解析的上下文
     */
    public function crmContextJson(): string
    {
        $students = Student::orderBy('name')->get(['id', 'name', 'phone', 'coach_name', 'lessons_total']);

        $members = MembershipCard::orderBy('member_name')->get()
            ->groupBy(fn (MembershipCard $c) => $c->member_name)
            ->map(function ($cards) {
                return [
                    'name' => $cards->first()->member_name,
                    'phone' => $cards->first()->phone,
                    'cards' => $cards->map(fn (MembershipCard $c) => [
                        'type' => $c->card_type,
                        'total_count' => $c->total_count,
                        'used_count' => $c->used_count,
                        'expired' => $c->isExpired(),
                    ])->values(),
                ];
            })
            ->values();

        $coaches = BookingRecord::where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('coach_name', '!=', '')
            ->pluck('coach_name')
            ->merge($students->pluck('coach_name')->filter())
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->sort()
            ->values();

        return (string) json_encode([
            'students' => $students->map(fn (Student $s) => [
                'name' => $s->name,
                'phone' => $s->phone,
                'coach_name' => $s->coach_name,
                'lessons_total' => $s->lessons_total,
            ]),
            'members' => $members,
            'coaches' => $coaches,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* -----------------------------------------------------------------
     | 内部工具
     | ----------------------------------------------------------------- */

    /**
     * 当前机构 code：优先当前登录用户，其次第一个机构（CLI/脚本兜底）
     */
    private function orgCode(): string
    {
        return (string) (auth('web')->user()?->organization_code
            ?? \App\Models\Organization::query()->value('code')
            ?? '');
    }

    /**
     * 手机号归一化：只留数字，兼容 +86 / 86 前缀、空格与连字符
     *
     * @return string 合法返回 11 位手机号，否则空串
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (preg_match('/^86(\d{11})$/', $digits, $m) === 1) {
            $digits = $m[1];
        }

        return preg_match('/^1[3-9]\d{9}$/', $digits) === 1 ? $digits : '';
    }

    /**
     * 计算卡期：用户给了就按用户；缺省按今天起（月卡 1 个月 / 年卡 1 年；次卡可不限）
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function resolvePeriod(array $data, string $type): array
    {
        $startAt = $this->tryParseDate($data['start_at'] ?? null);
        $endAt = $this->tryParseDate($data['end_at'] ?? null);

        if ($startAt === null && $endAt !== null) {
            // 只有结束日期：往前推（月卡/年卡按典型时长）
            $startAt = $type === MembershipCard::TYPE_MONTH
                ? $endAt->copy()->subMonth()
                : $endAt->copy()->subYear();
        }

        if ($startAt === null && $endAt === null && $type !== MembershipCard::TYPE_VISITS) {
            $startAt = Carbon::today('Asia/Shanghai');
            $endAt = $type === MembershipCard::TYPE_MONTH
                ? $startAt->copy()->addMonth()
                : $startAt->copy()->addYear();
        }

        return [$startAt, $endAt];
    }

    private function tryParseDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tryParseDateTime(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 提取学员名数组（支持 student_names 数组或单个 student_name）
     */
    private function studentNames(array $data): array
    {
        $names = $data['student_names'] ?? [];

        if (is_string($names)) {
            $names = [$names];
        }

        $names = array_values(array_filter((array) $names, fn ($n) => trim((string) $n) !== ''));

        // 兼容旧字段单个学员
        if (empty($names)) {
            $single = trim((string) ($data['student_name'] ?? ''));
            if ($single !== '') {
                $names = [$single];
            }
        }

        return array_map(fn ($n) => trim((string) $n), $names);
    }

    /**
     * 若同名会员已有其他进行中的卡，善意提示（排除刚办的这张，不阻止开新卡）
     */
    private function activeCardNote(string $name, int $excludeId = 0): string
    {
        $query = MembershipCard::where('member_name', $name);
        if ($excludeId > 0) {
            $query->where('id', '!=', $excludeId);
        }

        $exists = $query->get()
            ->first(fn (MembershipCard $c) => ! $c->isExpired()
                && ($c->card_type === MembershipCard::TYPE_VISITS ? $c->remaining_count > 0 : true));

        if (! $exists) {
            return '';
        }

        $note = $exists->card_type === MembershipCard::TYPE_VISITS
            ? $exists->type_label.'（剩 '.$exists->remaining_count.' 次）'
            : $exists->type_label.'（至 '.$exists->end_at->format('Y-m-d').'）';

        return '（提示：'.$name.'名下已有一张进行中的'.$note.'，可在聊天里说"'.$name.'用了一次"报备消耗）';
    }
}
