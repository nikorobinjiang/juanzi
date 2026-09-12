<?php

namespace Tests\Feature;

use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 约课自动建档测试
 *
 * 规则：约课成功后自动建档（课时 0；教练为空时回填本次教练）；仅新建档案时回复里提示补手机号。
 */
class BookingStudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = app(BookingService::class);
    }

    /** 造一个指定机构的用户 */
    private function makeUser(string $username, string $org): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
        ]);
    }

    /** 约课入参（默认：小美 / 王教练 / 明天 10:00 / 自动分配场地） */
    private function payload(array $attrs = []): array
    {
        return [
            'student_name' => '小美',
            'coach_name' => '王教练',
            'start_at' => now()->addDays(1)->setTime(10, 0)->format('Y-m-d H:i'),
            ...$attrs,
        ];
    }

    /** 新姓名约课：自动建档，课时 0、教练为本次教练，回复带补手机号提示 */
    public function test_create_auto_creates_student_profile(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        $result = $this->booking->create($this->payload());

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('已自动建立学员档案', $result['message']);
        $this->assertStringContainsString('手机号', $result['message']);

        $student = Student::where('name', '小美')->first();
        $this->assertNotNull($student);
        $this->assertSame('tennis_a', $student->organization_code);
        $this->assertSame(0, (int) $student->lessons_total);
        $this->assertSame('王教练', $student->coach_name);
    }

    /** 档案已有其他教练：不覆盖教练、不累加课时，回复不带建档提示 */
    public function test_create_keeps_existing_coach_and_total(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        Student::create([
            'name' => '小美',
            'coach_name' => '李教练',
            'lessons_total' => 5,
        ]);

        $result = $this->booking->create($this->payload(['coach_name' => '王教练']));

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('学员档案', $result['message']);

        $student = Student::where('name', '小美')->first();
        $this->assertSame('李教练', $student->coach_name);
        $this->assertSame(5, (int) $student->lessons_total);
        $this->assertSame(1, Student::count());
    }

    /** 档案没有教练：用本次约课教练补上，课时不累加，回复不带建档提示 */
    public function test_create_fills_empty_coach_without_hint(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        Student::create(['name' => '小美', 'lessons_total' => 3]);

        $result = $this->booking->create($this->payload(['coach_name' => '王教练']));

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('学员档案', $result['message']);

        $student = Student::where('name', '小美')->first();
        $this->assertSame('王教练', $student->coach_name);
        $this->assertSame(3, (int) $student->lessons_total);
    }

    /** 学员姓名为空：照常落库约课，但不建档 */
    public function test_create_without_student_name_skips_profile(): void
    {
        $this->actingAs($this->makeUser('coach_a', 'tennis_a'));

        $result = $this->booking->create($this->payload(['student_name' => '']));

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('学员档案', $result['message']);
        $this->assertSame(0, Student::count());
    }

    /** 机构隔离：B 机构同名约课各自建档，A 机构原档案不被改写 */
    public function test_same_name_in_other_organization_gets_its_own_profile(): void
    {
        $orgA = $this->makeUser('coach_a', 'tennis_a');
        $orgB = $this->makeUser('coach_b', 'tennis_b');

        $this->actingAs($orgA);
        Student::create([
            'name' => '小美',
            'coach_name' => '李教练',
            'lessons_total' => 2,
        ]);

        $this->actingAs($orgB);
        $result = $this->booking->create($this->payload(['coach_name' => '王教练']));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('已自动建立学员档案', $result['message']);

        // B 机构只看到自己那条
        $this->assertSame(1, Student::count());
        $studentB = Student::where('name', '小美')->first();
        $this->assertSame('tennis_b', $studentB->organization_code);
        $this->assertSame('王教练', $studentB->coach_name);
        $this->assertSame(0, (int) $studentB->lessons_total);

        // 全量看：两个机构各一条
        $this->assertSame(2, Student::withoutGlobalScope(OrganizationScope::class)->count());

        // A 机构原档案未被修改
        $this->actingAs($orgA);
        $studentA = Student::where('name', '小美')->first();
        $this->assertSame('tennis_a', $studentA->organization_code);
        $this->assertSame('李教练', $studentA->coach_name);
        $this->assertSame(2, (int) $studentA->lessons_total);
        $this->assertSame(1, Student::count());
    }
}
