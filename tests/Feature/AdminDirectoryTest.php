<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\MembershipCard;
use App\Models\MembershipUsage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台学员档案与会员卡：增删改查、校验与机构隔离
 */
class AdminDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = User::create([
            'name' => 'root', 'username' => 'root', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_ADMIN,
        ]);

        $this->boss = User::create([
            'name' => 'boss', 'username' => 'boss', 'password' => 'secret123',
            'organization_code' => 'alan_tennis', 'role' => User::ROLE_ORG_ADMIN,
        ]);
    }

    /** 学员档案新建 / 编辑 / 删除 */
    public function test_student_crud(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/students', [
            'name' => '王小明', 'phone' => '13800001111', 'coach_name' => '孟宇', 'lessons_total' => 10,
        ])->assertCreated()->json('student.id');

        $student = Student::find($id);

        $this->assertSame('tennis_a', $student->organization_code);
        $this->assertSame(10, (int) $student->lessons_total);

        $this->putJson('/api/admin/students/'.$id, ['name' => '王小明', 'lessons_total' => 20])
            ->assertOk()
            ->assertJsonPath('student.lessons_total', 20);

        $this->deleteJson('/api/admin/students/'.$id)->assertOk();
        $this->assertNull(Student::find($id));
    }

    /** 同名学员不允许重复建档 */
    public function test_duplicate_student_name_is_rejected(): void
    {
        $this->actingAs($this->root);

        $this->postJson('/api/admin/students', ['name' => '李雷', 'lessons_total' => 5])->assertCreated();
        $this->postJson('/api/admin/students', ['name' => '李雷', 'lessons_total' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /** 学员改名会同步约课记录里的姓名，避免历史记录找不到人 */
    public function test_renaming_student_syncs_booking_records(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/students', ['name' => '王小明', 'lessons_total' => 3])
            ->assertCreated()->json('student.id');

        BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '王小明',
            'coach_name' => '孟宇',
            'status' => BookingRecord::STATUS_BOOKED,
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'venue' => '1号场',
        ]);

        $this->putJson('/api/admin/students/'.$id, ['name' => '王大明'])->assertOk();

        $this->assertSame('王大明', BookingRecord::where('organization_code', 'tennis_a')->value('student_name'));
    }

    /** 会员卡：次卡要有总次数，月卡要有起止日期且不能倒挂 */
    public function test_card_type_requirements(): void
    {
        $this->actingAs($this->root);

        $this->postJson('/api/admin/member-cards', [
            'member_name' => '韩梅梅', 'card_type' => 'visits',
        ])->assertUnprocessable()->assertJsonValidationErrors('total_count');

        $this->postJson('/api/admin/member-cards', [
            'member_name' => '韩梅梅', 'card_type' => 'month',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_at');

        $this->postJson('/api/admin/member-cards', [
            'member_name' => '韩梅梅', 'card_type' => 'month',
            'start_at' => '2026-09-20', 'end_at' => '2026-09-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_at');

        $this->postJson('/api/admin/member-cards', [
            'member_name' => '韩梅梅', 'card_type' => 'month',
            'start_at' => '2026-09-01', 'end_at' => '2026-10-01',
        ])->assertCreated()->assertJsonPath('card.card_type_label', '月卡');
    }

    /** 会员卡剩余次数与到期状态 */
    public function test_card_remaining_and_expiry(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/member-cards', [
            'member_name' => '李雷', 'card_type' => 'visits', 'total_count' => 10,
        ])->assertCreated()->json('card.id');

        $this->getJson('/api/admin/member-cards')
            ->assertOk()
            ->assertJsonPath('cards.items.0.remaining_count', 10);

        $this->putJson('/api/admin/member-cards/'.$id, ['used_count' => 4])->assertOk();

        $this->getJson('/api/admin/member-cards')
            ->assertOk()
            ->assertJsonPath('cards.items.0.remaining_count', 6);

        // 过期月卡会标红
        $expiredId = $this->postJson('/api/admin/member-cards', [
            'member_name' => '过期卡', 'card_type' => 'year',
            'start_at' => '2025-01-01', 'end_at' => '2025-12-31',
        ])->assertCreated()->json('card.id');

        $this->assertNotNull($expiredId);
        $this->assertTrue(MembershipCard::find($expiredId)->isExpired());
    }

    /** 删除会员卡会连使用记录一起清掉 */
    public function test_deleting_card_removes_usages(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/member-cards', [
            'member_name' => '王五', 'card_type' => 'visits', 'total_count' => 5,
        ])->assertCreated()->json('card.id');

        MembershipUsage::create([
            'organization_code' => 'tennis_a',
            'card_id' => $id,
            'used_at' => now(),
        ]);

        $this->deleteJson('/api/admin/member-cards/'.$id)->assertOk();

        $this->assertSame(0, MembershipUsage::withoutGlobalScopes()->where('card_id', $id)->count());
    }

    /** 机构隔离：机构管理员动不了别的机构的档案 */
    public function test_directory_is_scoped_by_organization(): void
    {
        $this->actingAs($this->root);

        $studentId = $this->postJson('/api/admin/students?org=tennis_a', ['name' => '网球学员'])
            ->assertCreated()->json('student.id');

        $this->actingAs($this->boss);

        // 越权指定机构一律拒绝
        $this->postJson('/api/admin/students?org=tennis_a', ['name' => '偷建的学员'])->assertUnprocessable();
        $this->putJson('/api/admin/students/'.$studentId, ['name' => '改个名'])->assertUnprocessable();
        $this->deleteJson('/api/admin/students/'.$studentId)->assertUnprocessable();

        // 自己机构的列表里看不到别的机构的数据
        $this->getJson('/api/admin/students')->assertOk()->assertJsonPath('students.total', 0);
        $this->assertSame('网球学员', Student::withoutGlobalScopes()->find($studentId)->name);
    }

    /** 学员详情带剩余课时 */
    public function test_student_detail_includes_remaining_lessons(): void
    {
        $this->actingAs($this->root);

        $id = $this->postJson('/api/admin/students', ['name' => '小课', 'lessons_total' => 8])
            ->assertCreated()->json('student.id');

        BookingRecord::create([
            'organization_code' => 'tennis_a',
            'student_name' => '小课',
            'coach_name' => '孟宇',
            'status' => BookingRecord::STATUS_BOOKED,
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'venue' => '1号场',
        ]);

        $this->getJson('/api/admin/students/'.$id)
            ->assertOk()
            ->assertJsonPath('student.lesson_count', 1)
            ->assertJsonPath('student.lessons_remaining', 7);
    }
}
