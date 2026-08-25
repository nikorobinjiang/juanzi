<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\GeneratedImage;
use App\Models\Message;
use App\Models\User;
use App\Services\ExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 账号体系与机构级数据隔离测试
 */
class AuthOrganizationTest extends TestCase
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

    /** 以指定用户身份造一条约课记录 */
    private function makeBooking(User $user, array $attrs = []): BookingRecord
    {
        $this->actingAs($user);

        $startAt = $attrs['start_at'] ?? now()->addDays(1)->setTime(10, 0);

        return BookingRecord::create([
            'student_name' => '小明',
            'coach_name' => '王教练',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHour(),
            'venue' => '1A',
            'status' => BookingRecord::STATUS_BOOKED,
            'remark' => '',
            ...$attrs,
        ]);
    }

    /** 注册成功：创建用户、自动登录、跳转约课页 */
    public function test_register_creates_user_and_logs_in(): void
    {
        $response = $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'swim',
        ]);

        $response->assertRedirect('/appoints');
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'username' => 'xiaoming',
            'organization_code' => 'swim',
        ]);
    }

    /** 注册校验：用户名重复 / 机构不在配置内 / 密码不一致 */
    public function test_register_validates_input(): void
    {
        $this->makeUser('xiaoming', 'swim');

        $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'swim',
        ])->assertSessionHasErrors('username');

        $this->post('/register', [
            'username' => 'xiaohong',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'not-exists',
        ])->assertSessionHasErrors('organization_code');

        $this->post('/register', [
            'username' => 'xiaohong',
            'password' => 'secret123',
            'password_confirmation' => 'different',
            'organization_code' => 'swim',
        ])->assertSessionHasErrors('password');
    }

    /** 登录（机构 + 用户名 + 密码）/ 登出 */
    public function test_login_and_logout(): void
    {
        $this->makeUser('xiaoming', 'swim');

        $this->post('/login', [
            'organization_code' => 'swim',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertRedirect('/appoints');
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    /** 登录校验：机构不在配置内 / 所选机构与用户注册机构不一致 / 密码错误 */
    public function test_login_validates_organization(): void
    {
        $this->makeUser('xiaoming', 'swim');

        // 机构不在配置内
        $this->post('/login', [
            'organization_code' => 'not-exists',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertSessionHasErrors('organization_code');
        $this->assertGuest();

        // 所选机构与用户注册时所属机构不一致（选错机构，防止进错工作区）
        $this->post('/login', [
            'organization_code' => 'ball',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        // 机构正确但密码错误
        $this->post('/login', [
            'organization_code' => 'swim',
            'username' => 'xiaoming',
            'password' => 'wrong-pass',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /** 未登录访问页面跳登录页、访问 API 返回 401 */
    public function test_guest_redirected_to_login_and_api_returns_401(): void
    {
        $this->get('/appoints')->assertRedirect('/login');
        $this->getJson('/api/booking')->assertStatus(401);
        $this->postJson('/api/chat', ['message' => '你好'])->assertStatus(401);
    }

    /** 跨机构数据不可见：A 机构约课 B 机构通过 Eloquent 与 API 均查不到 */
    public function test_cross_organization_data_invisible(): void
    {
        $userA = $this->makeUser('alice', 'swim');
        $userB = $this->makeUser('bob', 'ball');

        $booking = $this->makeBooking($userA);
        $this->assertSame('swim', $booking->organization_code);

        // A 机构自己能看到
        $this->actingAs($userA);
        $this->assertNotNull(BookingRecord::find($booking->id));

        // B 机构通过 Eloquent 查不到（全局作用域自动隔离）
        $this->actingAs($userB);
        $this->assertNull(BookingRecord::find($booking->id));
        $this->assertSame(0, BookingRecord::count());

        // B 机构通过 API 也看不到
        $this->getJson('/api/booking')->assertOk()->assertJsonPath('weeks', []);
    }

    /** 同机构多账号共享数据 */
    public function test_same_organization_shares_data(): void
    {
        $userA = $this->makeUser('alice', 'swim');
        $userC = $this->makeUser('carol', 'swim');

        $booking = $this->makeBooking($userA);

        $this->actingAs($userC);
        $this->assertNotNull(BookingRecord::find($booking->id));
        $this->assertSame(1, BookingRecord::count());
    }

    /** 聊天记录与生成图片同样按机构隔离，且创建时自动填充机构 */
    public function test_messages_and_images_isolated_by_org(): void
    {
        $userA = $this->makeUser('alice', 'swim');
        $userB = $this->makeUser('bob', 'ball');

        $this->actingAs($userA);
        $message = Message::create(['role' => 'user', 'type' => 'text', 'content' => '你好']);
        $image = GeneratedImage::create([
            'style_key' => 'a',
            'user_image' => 'uploads/demo.png',
            'output_image' => 'generated/out.png',
        ]);

        $this->assertSame('swim', $message->organization_code);
        $this->assertSame('swim', $image->organization_code);

        // B 机构查不到
        $this->actingAs($userB);
        $this->assertNull(Message::find($message->id));
        $this->assertNull(GeneratedImage::find($image->id));
        $this->assertSame(0, Message::count());
        $this->assertSame(0, GeneratedImage::count());
    }

    /** Excel 文件名带机构前缀；同机构可下载、跨机构下载被拒 */
    public function test_excel_filename_and_download_isolated_by_org(): void
    {
        $userA = $this->makeUser('alice', 'swim');
        $userB = $this->makeUser('bob', 'ball');

        $this->actingAs($userA);
        $result = app(ExcelService::class)->generate();
        $filename = $result['filename'];

        $this->assertStringStartsWith('swim_', $filename);

        // 同机构可下载
        $this->get('/api/excel/download/'.rawurlencode($filename))->assertOk();

        // 跨机构下载被拒（404）
        $this->actingAs($userB);
        $this->get('/api/excel/download/'.rawurlencode($filename))->assertNotFound();
    }
}
