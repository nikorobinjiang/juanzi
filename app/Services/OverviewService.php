<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\MembershipCard;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * 数据概览：学员 / 会员 / 教练聚合统计与模糊搜索（姓名 / 手机号）
 *
 * 统计口径与聊天一致：只要创建约课记录即计入（非已取消）。
 * 数据量小，所有聚合在内存完成，避免 N+1。
 */
class OverviewService
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_STUDENTS = 'students';
    public const SCOPE_MEMBERS = 'members';
    public const SCOPE_COACHES = 'coaches';

    public const SCOPES = [
        self::SCOPE_STUDENTS,
        self::SCOPE_MEMBERS,
        self::SCOPE_COACHES,
    ];

    /**
     * 统一搜索入口
     *
     * @return array{
     *     q: string,
     *     scope: string,
     *     students: array,
     *     members: array,
     *     coaches: array,
     * }
     */
    public function search(string $q, string $scope): array
    {
        $q = trim($q);
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_ALL;

        $result = [
            'q' => $q,
            'scope' => $scope,
            'students' => [],
            'members' => [],
            'coaches' => [],
        ];

        if ($scope === self::SCOPE_ALL || $scope === self::SCOPE_STUDENTS) {
            $result['students'] = $this->students($q);
        }
        if ($scope === self::SCOPE_ALL || $scope === self::SCOPE_MEMBERS) {
            $result['members'] = $this->members($q);
        }
        if ($scope === self::SCOPE_ALL || $scope === self::SCOPE_COACHES) {
            $result['coaches'] = $this->coaches($q);
        }

        return $result;
    }

    /**
     * 学员详情（档案 + 统计 + 最近约课记录）
     */
    public function studentDetail(int $id): ?array
    {
        $student = Student::find($id);
        if (! $student) {
            return null;
        }

        $bookings = BookingRecord::where('student_name', 'like', '%'.$student->name.'%')
            ->orderByDesc('start_at')
            ->limit(30)
            ->get();

        $lessonCount = $this->countLessonsByName($student->name);

        return [
            'id' => $student->id,
            'name' => $student->name,
            'phone' => $student->phone,
            'coach_name' => $student->coach_name,
            'lessons_total' => $student->lessons_total,
            'lesson_count' => $lessonCount,
            'lessons_remaining' => max(0, $student->lessons_total - $lessonCount),
            'remark' => $student->remark,
            'bookings' => $bookings->map(fn (BookingRecord $b) => [
                'id' => $b->id,
                'coach_name' => $b->coach_name,
                'start_at' => $b->start_at?->format('Y-m-d H:i'),
                'venue' => $b->venue,
                'status' => $b->status,
                'status_label' => $b->status_label,
            ])->values(),
        ];
    }

    /**
     * 会员详情：按姓名取该会员名下全部卡（列表内同人聚合即按姓名）
     */
    public function memberDetail(string $name): ?array
    {
        $cards = MembershipCard::where('member_name', $name)
            ->orderByDesc('start_at')
            ->get();

        if ($cards->isEmpty()) {
            return null;
        }

        return $this->memberPayload($cards);
    }

    /* -----------------------------------------------------------------
     | 各模块聚合
     | ----------------------------------------------------------------- */

    /**
     * 学员列表：模糊匹配姓名/手机号
     */
    private function students(string $q): array
    {
        $query = Student::query();
        if ($q !== '') {
            $query->where(fn ($b) => $b->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%"));
        }

        $students = $query->orderBy('name')->get();

        return $students->map(function (Student $s) {
            $lessonCount = $this->countLessonsByName($s->name);

            return [
                'id' => $s->id,
                'name' => $s->name,
                'phone' => $s->phone,
                'coach_name' => $s->coach_name,
                'lessons_total' => $s->lessons_total,
                'lesson_count' => $lessonCount,
                'lessons_remaining' => max(0, $s->lessons_total - $lessonCount),
            ];
        })->values()->all();
    }

    /**
     * 会员列表：按人聚合名下所有卡
     */
    private function members(string $q): array
    {
        $query = MembershipCard::query();
        if ($q !== '') {
            $query->where(fn ($b) => $b->where('member_name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%"));
        }

        $cards = $query->orderByDesc('start_at')->get();
        $grouped = $cards->groupBy(fn (MembershipCard $c) => $c->member_name);

        return $grouped->map(fn (Collection $personCards) => $this->memberPayload($personCards))
            ->values()
            ->all();
    }

    /**
     * 把某会员的卡集合组装成会员条目
     *
     * @param  Collection<int, MembershipCard>  $personCards
     */
    private function memberPayload(Collection $personCards): array
    {
        $first = $personCards->first();

        return [
            'name' => $first->member_name,
            'phone' => $first->phone,
            'cards' => $personCards->map(fn (MembershipCard $c) => [
                'id' => $c->id,
                'card_type' => $c->card_type,
                'type_label' => $c->type_label,
                'total_count' => $c->total_count,
                'start_at' => $c->start_at?->format('Y-m-d'),
                'end_at' => $c->end_at?->format('Y-m-d'),
                'used_count' => $c->used_count,
                'remaining_count' => $c->remaining_count,
                'remaining_days' => $c->remaining_days,
                'expired' => $c->isExpired(),
            ])->values(),
        ];
    }

    /**
     * 教练列表：姓名 / 上课次数 / 在教(绑定该教练的)学员
     */
    private function coaches(string $q): array
    {
        // 教练名集合 = 约课记录里的教练 ∪ 学员档案绑定的当前教练
        $names = collect();
        $names = $names->merge(
            BookingRecord::where('status', '!=', BookingRecord::STATUS_CANCELLED)
                ->where('coach_name', '!=', '')
                ->pluck('coach_name')
        );
        $names = $names->merge(
            Student::whereNotNull('coach_name')->where('coach_name', '!=', '')->pluck('coach_name')
        );

        $names = $names->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->sortBy(fn ($n) => $n)
            ->values();

        // 学员档案按教练预分组，避免 N+1
        $studentsByCoach = Student::query()->orderBy('name')->get()
            ->groupBy(fn (Student $s) => (string) $s->coach_name);

        return $names
            ->filter(function (string $name) use ($q) {
                return $q === '' || mb_stripos($name, $q) !== false;
            })
            ->map(function (string $name) use ($studentsByCoach) {
                $coachStudents = $studentsByCoach->get($name, collect());

                return [
                    'name' => $name,
                    'lessons' => $this->countCoachLessons($name),
                    'teaching_students' => $coachStudents->map(fn (Student $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'phone' => $s->phone,
                    ])->values(),
                ];
            })
            ->values()
            ->all();
    }

    /* -----------------------------------------------------------------
     | 内部工具
     | ----------------------------------------------------------------- */

    /**
     * 学员上课次数：全机构非取消约课一次读入，内存模糊匹配（名字完全一致或互为子串）
     */
    private function countLessonsByName(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        return $this->bookingStudentNames()
            ->filter(fn (string $bookingName) => $this->nameMatches($bookingName, $name))
            ->count();
    }

    private function countCoachLessons(string $coach): int
    {
        if ($coach === '') {
            return 0;
        }

        return $this->bookingCoachNames()
            ->filter(fn (string $bookingCoach) => $this->nameMatches($bookingCoach, $coach))
            ->count();
    }

    /**
     * 约课记录里学员姓名集合（非已取消，按机构隔离，带权重复）
     */
    private function bookingStudentNames(): Collection
    {
        return BookingRecord::where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('student_name', '!=', '')
            ->pluck('student_name');
    }

    /**
     * 约课记录里教练姓名集合（非已取消，按机构隔离，带权重复）
     */
    private function bookingCoachNames(): Collection
    {
        return BookingRecord::where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->where('coach_name', '!=', '')
            ->pluck('coach_name');
    }

    /**
     * 模糊名字匹配：完全相等或互为子串（如约课表里"王小明"与档案"小明"）
     */
    private function nameMatches(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);

        return $a !== '' && $b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a));
    }
}
