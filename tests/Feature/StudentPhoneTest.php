<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\CrmService;
use App\Services\DoubaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 学员手机号登记：聊天说「小明手机号138xxxxxxxx」要写进档案，不能被当闲聊拦掉
 */
class StudentPhoneTest extends TestCase
{
    use RefreshDatabase;

    /** 造一个指定机构的用户（密码明文，模型 hashed cast 自动哈希） */
    private function makeUser(string $username, string $org): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
        ]);
    }

    /** 带前缀动词的说法走豆包意图解析：update_phone 分支写入档案 */
    public function test_chat_registers_phone_via_intent(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        Student::create([
            'name' => '小明',
            'organization_code' => 'tennis_a',
            'lessons_total' => 0,
        ]);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->never(); // 本地关键词直接放行
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'update_phone',
                'data' => ['student_name' => '小明', 'phone' => '13800000000'],
                'reply' => '',
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '帮小明登记一下手机号 13800000000']);

        $response->assertOk();
        $this->assertStringContainsString('13800000000', $response->json('reply'));
        $this->assertSame('13800000000', Student::where('name', '小明')->first()->phone);
    }

    /** 已有号码改为新号：文案是「旧号 → 新号」，库里落新号 */
    public function test_chat_changes_existing_phone(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        Student::create([
            'name' => '小明',
            'organization_code' => 'tennis_a',
            'phone' => '13700000000',
            'lessons_total' => 0,
        ]);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'update_phone',
                'data' => ['student_name' => '小明', 'phone' => '13800000000'],
                'reply' => '',
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '帮小明把电话改成13800000000']);

        $response->assertOk();
        $this->assertStringContainsString('13700000000', $response->json('reply'));
        $this->assertStringContainsString('13800000000', $response->json('reply'));
        $this->assertSame('13800000000', Student::where('name', '小明')->first()->phone);
    }

    /** 学员还没档案：顺手建档并登记手机号，课时保持 0（不买课） */
    public function test_registers_phone_creates_profile_on_demand(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'update_phone',
                'data' => ['student_name' => '小红', 'phone' => '13800001111'],
                'reply' => '',
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '请登记小红手机号13800001111']);

        $response->assertOk();

        $student = Student::where('name', '小红')->first();
        $this->assertNotNull($student);
        $this->assertSame('13800001111', $student->phone);
        $this->assertSame('tennis_a', $student->organization_code);
        $this->assertSame(0, (int) $student->lessons_total);
    }

    /** 号码归一化（+86 / 连字符 / 空格）与非法输入 */
    public function test_normalizes_phone_and_rejects_invalid_input(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        /** @var CrmService $crm */
        $crm = app(CrmService::class);

        Student::create([
            'name' => '小红',
            'organization_code' => 'tennis_a',
            'lessons_total' => 0,
        ]);

        // +86 / 连字符 / 空格都能归一成 11 位
        $this->assertStringContainsString(
            '13800000000',
            $crm->updateStudentPhone(['student_name' => '小红', 'phone' => '+86 138-0000-0000'])
        );
        $this->assertSame('13800000000', Student::where('name', '小红')->first()->phone);

        // 非法号码：不覆盖已有号码
        $this->assertStringContainsString(
            '没识别出手机号',
            $crm->updateStudentPhone(['student_name' => '小红', 'phone' => '123'])
        );
        $this->assertSame('13800000000', Student::where('name', '小红')->first()->phone);

        // 没说哪位学员：引导补充
        $this->assertStringContainsString('哪位学员', $crm->updateStudentPhone(['phone' => '13800000000']));
    }

    /** 本地快路径：「小明手机号13800000000」直陈句式不调豆包，直接写库（毫秒级返回） */
    public function test_phone_message_is_answered_locally_without_doubao(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        // 豆包被 mock 后任何调用都会报错：走本地快路径时两个方法都不该被调用
        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->never();
            $mock->shouldReceive('parseBookingAction')->never();
        });

        $response = $this->postJson('/api/chat', ['message' => '小明手机号13800000000']);

        $response->assertOk();
        $this->assertStringContainsString('13800000000', $response->json('reply'));
        $this->assertSame('13800000000', Student::where('name', '小明')->first()->phone);
    }

    /** 号码带空格 / 分隔符同样走快路径 */
    public function test_phone_message_with_spaces_is_answered_locally(): void
    {
        $this->actingAs($this->makeUser('alice', 'tennis_a'));

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->never();
            $mock->shouldReceive('parseBookingAction')->never();
        });

        $response = $this->postJson('/api/chat', ['message' => '小明的电话 138 0000 2222']);

        $response->assertOk();
        $this->assertSame('13800002222', Student::where('name', '小明')->first()->phone);
    }
}
