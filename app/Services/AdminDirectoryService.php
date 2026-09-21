<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\MembershipCard;
use App\Models\MembershipUsage;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Support\AdminContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 后台：学员档案与会员卡（名录类数据的增删改查）
 *
 * 与既有业务保持一致的地方：
 * - 机构隔离：显式 organization_code + withoutGlobalScope，不动全局 Scope 的口径
 * - 学员改名：同步刷 booking_records.student_name 与 membership_cards.member_name，
 *   否则改名后约课记录与会员卡会"找不到人"（与教练改名的处理思路一致）
 * - 课时：lessons_total 只由人工维护，约课不回写，剩余课时按约课记录聚合计算
 */
class AdminDirectoryService
{
    public function __construct(private readonly AdminContext $admin) {}

    /**
     * 学员列表
     *
     * 列表不逐条算 lesson_count（那是按约课记录 count 的聚合，列表页会拖垮），
     * 只展示总课时；剩余课时在详情里算。
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function students(string $orgCode, string $keyword = '', int $perPage = 20): array
    {
        $this->assertManageable($orgCode);

        $query = Student::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode);

        $keyword = trim($keyword);

        if ($keyword !== '') {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('phone', 'like', '%'.$keyword.'%')
                ->orWhere('coach_name', 'like', '%'.$keyword.'%'));
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->orderBy('name')->paginate($perPage);

        return $this->paged($page, fn (Student $student) => $this->presentStudent($student));
    }

    /**
     * 会员卡列表（默认在用的在前，已过期的在后）
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function memberCards(string $orgCode, string $keyword = '', int $perPage = 20): array
    {
        $this->assertManageable($orgCode);

        $query = MembershipCard::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode);

        $keyword = trim($keyword);

        if ($keyword !== '') {
            $query->where(fn ($q) => $q
                ->where('member_name', 'like', '%'.$keyword.'%')
                ->orWhere('phone', 'like', '%'.$keyword.'%'));
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->orderByDesc('id')->paginate($perPage);

        return $this->paged($page, fn (MembershipCard $card) => $this->presentCard($card));
    }

    /**
     * 新建学员档案
     *
     * @param  array<string, mixed>  $data  name / phone / coach_name / lessons_total / remark
     */
    public function createStudent(array $data, string $orgCode): Student
    {
        $this->assertManageable($orgCode);
        $this->assertStudentNameFree((string) $data['name'], $orgCode);

        $student = Student::create([
            'organization_code' => $orgCode,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'coach_name' => $data['coach_name'] ?? null,
            'lessons_total' => (int) ($data['lessons_total'] ?? 0),
            'remark' => $data['remark'] ?? null,
        ]);

        Log::info('后台新建学员档案', [
            'operator_id' => $this->admin->user()?->getKey(),
            'organization_code' => $orgCode,
            'student_id' => $student->getKey(),
        ]);

        return $student;
    }

    /**
     * 编辑学员档案（改名会同步业务表里的姓名字符串）
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStudent(Student $student, array $data): Student
    {
        $this->assertManageable((string) $student->organization_code);

        $newName = trim((string) ($data['name'] ?? ''));
        $oldName = (string) $student->name;

        if ($newName !== '' && $newName !== $oldName) {
            $this->assertStudentNameFree($newName, (string) $student->organization_code);
        }

        return DB::transaction(function () use ($student, $data, $oldName, $newName) {
            $student->fill([
                'name' => $newName !== '' ? $newName : $student->name,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : $student->phone,
                'coach_name' => array_key_exists('coach_name', $data) ? $data['coach_name'] : $student->coach_name,
                'lessons_total' => array_key_exists('lessons_total', $data)
                    ? (int) $data['lessons_total']
                    : $student->lessons_total,
                'remark' => array_key_exists('remark', $data) ? $data['remark'] : $student->remark,
            ])->save();

            if ($newName !== '' && $newName !== $oldName) {
                $this->syncStudentRename($oldName, $newName, (string) $student->organization_code);
            }

            Log::info('后台编辑学员档案', [
                'operator_id' => $this->admin->user()?->getKey(),
                'student_id' => $student->getKey(),
                'old' => $oldName,
                'new' => $student->name,
            ]);

            return $student;
        });
    }

    /**
     * 删除学员档案
     *
     * 约课记录按 student_name 关联、不删；只删档案本身。
     */
    public function deleteStudent(Student $student): void
    {
        $this->assertManageable((string) $student->organization_code);

        $id = $student->getKey();
        $student->delete();

        Log::info('后台删除学员档案', [
            'operator_id' => $this->admin->user()?->getKey(),
            'student_id' => $id,
            'organization_code' => $student->organization_code,
        ]);
    }

    /**
     * 新建会员卡
     *
     * 校验口径（控制器已校验一遍，这里再兜一次，防绕过）：
     * - 次卡必须有总次数；月卡 / 年卡必须有起止日期且结束不早于开始
     *
     * @param  array<string, mixed>  $data
     */
    public function createCard(array $data, string $orgCode): MembershipCard
    {
        $this->assertManageable($orgCode);
        $this->assertCardPayload($data);

        $card = MembershipCard::create([
            'organization_code' => $orgCode,
            'member_name' => $data['member_name'],
            'phone' => $data['phone'] ?? null,
            'card_type' => $data['card_type'],
            'total_count' => $data['total_count'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'used_count' => 0,
            'note' => $data['note'] ?? null,
        ]);

        Log::info('后台新建会员卡', [
            'operator_id' => $this->admin->user()?->getKey(),
            'organization_code' => $orgCode,
            'card_id' => $card->getKey(),
            'card_type' => $card->card_type,
        ]);

        return $card;
    }

    /**
     * 编辑会员卡（换卡型时按新卡型重新校验必填项）
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCard(MembershipCard $card, array $data): MembershipCard
    {
        $this->assertManageable((string) $card->organization_code);

        $merged = array_merge([
            'card_type' => $card->card_type,
            'total_count' => $card->total_count,
            'start_at' => $card->start_at?->format('Y-m-d'),
            'end_at' => $card->end_at?->format('Y-m-d'),
        ], $data);

        $this->assertCardPayload($merged);

        $card->fill([
            'member_name' => $data['member_name'] ?? $card->member_name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $card->phone,
            'card_type' => $merged['card_type'],
            'total_count' => $merged['total_count'],
            'start_at' => $merged['start_at'],
            'end_at' => $merged['end_at'],
            'used_count' => array_key_exists('used_count', $data) ? (int) $data['used_count'] : $card->used_count,
            'note' => array_key_exists('note', $data) ? $data['note'] : $card->note,
        ])->save();

        Log::info('后台编辑会员卡', [
            'operator_id' => $this->admin->user()?->getKey(),
            'card_id' => $card->getKey(),
        ]);

        return $card;
    }

    /**
     * 删除会员卡（连同使用记录一起删，避免留下孤儿流水）
     */
    public function deleteCard(MembershipCard $card): void
    {
        $this->assertManageable((string) $card->organization_code);

        $id = $card->getKey();

        DB::transaction(function () use ($card) {
            MembershipUsage::withoutGlobalScope(OrganizationScope::class)
                ->where('card_id', $card->getKey())
                ->delete();

            $card->delete();
        });

        Log::info('后台删除会员卡', [
            'operator_id' => $this->admin->user()?->getKey(),
            'card_id' => $id,
            'organization_code' => $card->organization_code,
        ]);
    }

    /**
     * 学员展示结构
     *
     * @return array<string, mixed>
     */
    public function presentStudent(Student $student): array
    {
        return [
            'id' => $student->getKey(),
            'name' => $student->name,
            'phone' => $student->phone,
            'coach_name' => $student->coach_name,
            'lessons_total' => (int) $student->lessons_total,
            'remark' => $student->remark,
            'organization_code' => $student->organization_code,
        ];
    }

    /**
     * 学员详情（含剩余课时，列表不加载）
     *
     * @return array<string, mixed>
     */
    public function studentDetail(Student $student): array
    {
        return $this->presentStudent($student) + [
            'lesson_count' => $student->lesson_count,
            'lessons_remaining' => $student->lessons_remaining,
        ];
    }

    /**
     * 会员卡展示结构
     *
     * @return array<string, mixed>
     */
    public function presentCard(MembershipCard $card): array
    {
        return [
            'id' => $card->getKey(),
            'member_name' => $card->member_name,
            'phone' => $card->phone,
            'card_type' => $card->card_type,
            'card_type_label' => $card->type_label,
            'total_count' => $card->total_count === null ? null : (int) $card->total_count,
            'used_count' => (int) $card->used_count,
            'remaining_count' => $card->card_type === MembershipCard::TYPE_VISITS ? $card->remaining_count : null,
            'start_at' => $card->start_at?->format('Y-m-d'),
            'end_at' => $card->end_at?->format('Y-m-d'),
            'remaining_days' => $card->end_at ? $card->remaining_days : null,
            'expired' => $card->isExpired(),
            'note' => $card->note,
            'organization_code' => $card->organization_code,
        ];
    }

    /**
     * 学员改名后同步业务表的姓名字符串
     */
    private function syncStudentRename(string $oldName, string $newName, string $orgCode): void
    {
        $bookings = BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('student_name', $oldName)
            ->update(['student_name' => $newName, 'updated_at' => now()]);

        $cards = MembershipCard::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('member_name', $oldName)
            ->update(['member_name' => $newName, 'updated_at' => now()]);

        Log::info('学员改名同步业务表', [
            'organization_code' => $orgCode,
            'old' => $oldName,
            'new' => $newName,
            'bookings' => $bookings,
            'cards' => $cards,
        ]);
    }

    /**
     * 机构内同名学员校验
     */
    private function assertStudentNameFree(string $name, string $orgCode): void
    {
        $exists = Student::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_code', $orgCode)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => '本机构已有同名学员']);
        }
    }

    /**
     * 会员卡字段校验：次卡要总数，期限卡要起止日期
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCardPayload(array $data): void
    {
        $type = (string) ($data['card_type'] ?? '');

        if (! in_array($type, [MembershipCard::TYPE_MONTH, MembershipCard::TYPE_YEAR, MembershipCard::TYPE_VISITS], true)) {
            throw ValidationException::withMessages(['card_type' => '请选择卡型']);
        }

        if ($type === MembershipCard::TYPE_VISITS) {
            if (! isset($data['total_count']) || (int) $data['total_count'] < 1) {
                throw ValidationException::withMessages(['total_count' => '次卡需填写总次数']);
            }

            return;
        }

        if (blank($data['start_at'] ?? null) || blank($data['end_at'] ?? null)) {
            throw ValidationException::withMessages(['end_at' => '月卡 / 年卡需填写起止日期']);
        }

        if (strtotime((string) $data['end_at']) < strtotime((string) $data['start_at'])) {
            throw ValidationException::withMessages(['end_at' => '到期日期不能早于起卡日期']);
        }
    }

    /**
     * 分页结果统一结构
     *
     * @param  callable  $present
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    private function paged(LengthAwarePaginator $page, callable $present): array
    {
        return [
            'items' => $page->getCollection()->map($present)->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * 目标机构必须在可管理范围内
     */
    private function assertManageable(string $orgCode): void
    {
        if (! $this->admin->canManage($orgCode)) {
            throw new AccessDeniedHttpException('无权管理该机构');
        }
    }
}
