<?php

namespace App\Http\Controllers;

use App\Models\MembershipCard;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Services\AdminDirectoryService;
use App\Support\AdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 后台：学员档案与会员卡接口
 */
class AdminDirectoryController extends Controller
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminDirectoryService $directory
    ) {}

    /** 学员列表：?q=&page=&per_page= */
    public function students(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        return response()->json([
            'organization_code' => $code,
            'students' => $this->directory->students(
                $code,
                mb_substr(trim((string) $request->input('q', '')), 0, 50),
                $this->perPage($request)
            ),
        ]);
    }

    /** 学员详情（含剩余课时） */
    public function student(int $id): JsonResponse
    {
        /** @var Student|null $student */
        $student = Student::withoutGlobalScope(OrganizationScope::class)->find($id);

        if (! $student) {
            throw new NotFoundHttpException('学员不存在');
        }

        if (! $this->admin->canManage((string) $student->organization_code)) {
            throw ValidationException::withMessages(['student' => '无权管理该学员']);
        }

        return response()->json(['student' => $this->directory->studentDetail($student)]);
    }

    /** 新建学员 */
    public function storeStudent(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'coach_name' => ['nullable', 'string', 'max:50'],
            'lessons_total' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => '请输入学员姓名',
            'name.max' => '学员姓名不能超过 50 个字符',
            'lessons_total.min' => '课时不能为负数',
        ]);

        $student = $this->directory->createStudent($validated, $code);

        return response()->json(['student' => $this->directory->presentStudent($student)], 201);
    }

    /** 编辑学员 */
    public function updateStudent(Request $request, int $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'coach_name' => ['nullable', 'string', 'max:50'],
            'lessons_total' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => '请输入学员姓名',
        ]);

        $student = $this->directory->updateStudent($student, $validated);

        return response()->json(['student' => $this->directory->presentStudent($student)]);
    }

    /** 删除学员 */
    public function destroyStudent(int $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->directory->deleteStudent($student);

        return response()->json(['message' => '学员档案已删除']);
    }

    /** 会员卡列表 */
    public function memberCards(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        return response()->json([
            'organization_code' => $code,
            'cards' => $this->directory->memberCards(
                $code,
                mb_substr(trim((string) $request->input('q', '')), 0, 50),
                $this->perPage($request)
            ),
        ]);
    }

    /** 新建会员卡 */
    public function storeCard(Request $request): JsonResponse
    {
        $code = $this->orgCode($request);

        $validated = $request->validate([
            'member_name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'card_type' => ['required', 'in:month,year,visits'],
            'total_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'used_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'member_name.required' => '请输入会员姓名',
            'card_type.required' => '请选择卡型',
            'card_type.in' => '卡型只能是月卡 / 年卡 / 次卡',
        ]);

        $card = $this->directory->createCard($validated, $code);

        return response()->json(['card' => $this->directory->presentCard($card)], 201);
    }

    /** 编辑会员卡 */
    public function updateCard(Request $request, int $id): JsonResponse
    {
        $card = $this->findCard($id);

        $validated = $request->validate([
            'member_name' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'card_type' => ['nullable', 'in:month,year,visits'],
            'total_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'used_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $card = $this->directory->updateCard($card, $validated);

        return response()->json(['card' => $this->directory->presentCard($card)]);
    }

    /** 删除会员卡 */
    public function destroyCard(int $id): JsonResponse
    {
        $card = $this->findCard($id);

        $this->directory->deleteCard($card);

        return response()->json(['message' => '会员卡已删除']);
    }

    /**
     * 目标机构：默认后台当前机构，总管理员可用 ?org= 指定
     */
    private function orgCode(Request $request): string
    {
        $wanted = trim((string) $request->input('org', ''));

        if ($wanted !== '' && ! $this->admin->canManage($wanted)) {
            throw ValidationException::withMessages(['org' => '无权管理该机构']);
        }

        return $wanted !== '' ? $wanted : $this->admin->currentCode();
    }

    private function perPage(Request $request): int
    {
        return max(5, min((int) $request->input('per_page', 20), 100));
    }

    private function findStudent(int $id): Student
    {
        /** @var Student|null $student */
        $student = Student::withoutGlobalScope(OrganizationScope::class)->find($id);

        if (! $student) {
            throw new NotFoundHttpException('学员不存在');
        }

        if (! $this->admin->canManage((string) $student->organization_code)) {
            throw ValidationException::withMessages(['student' => '无权管理该学员']);
        }

        return $student;
    }

    private function findCard(int $id): MembershipCard
    {
        /** @var MembershipCard|null $card */
        $card = MembershipCard::withoutGlobalScope(OrganizationScope::class)->find($id);

        if (! $card) {
            throw new NotFoundHttpException('会员卡不存在');
        }

        if (! $this->admin->canManage((string) $card->organization_code)) {
            throw ValidationException::withMessages(['card' => '无权管理该会员卡']);
        }

        return $card;
    }
}
