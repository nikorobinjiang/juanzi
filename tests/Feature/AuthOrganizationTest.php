<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\GeneratedImage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\ExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 账号体系与机构级数据隔离测试（机构来自 organizations 表，注册需认证码）
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

    /** 注册成功：首次注册写入机构认证码、创建用户、自动登录、跳转约课页 */
    public function test_register_creates_user_and_logs_in(): void
    {
        $response = $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'ABC123',
        ]);

        $response->assertRedirect('/appoints');
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'username' => 'xiaoming',
            'organization_code' => 'tennis_a',
        ]);

        // 首次注册：认证码持久化到 organizations 表（统一大写）
        $this->assertDatabaseHas('organizations', [
            'code' => 'tennis_a',
            'auth_code' => 'ABC123',
        ]);
    }

    /** 注册校验：用户名重复 / 机构无效 / 密码不一致 / 认证码格式错误 */
    public function test_register_validates_input(): void
    {
        $this->makeUser('xiaoming', 'tennis_a');

        $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'ABC123',
        ])->assertSessionHasErrors('username');

        $this->post('/register', [
            'username' => 'xiaohong',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'not-exists',
            'organization_auth_code' => 'ABC123',
        ])->assertSessionHasErrors('organization_code');

        $this->post('/register', [
            'username' => 'xiaohong',
            'password' => 'secret123',
            'password_confirmation' => 'different',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'ABC123',
        ])->assertSessionHasErrors('password');

        // 认证码非 6 位字母数字
        $this->post('/register', [
            'username' => 'xiaohong',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'ab',
        ])->assertSessionHasErrors('organization_auth_code');
    }

    /** 缺少认证码被拒绝 */
    public function test_register_requires_auth_code(): void
    {
        $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
        ])->assertSessionHasErrors('organization_auth_code');
    }

    /** 已初始化机构：认证码错误被拒 */
    public function test_register_rejects_wrong_auth_code(): void
    {
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'ABC123']);

        $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'XYZ789',
        ])->assertSessionHasErrors('organization_auth_code');
        $this->assertGuest();

        // 认证码未被篡改
        $this->assertDatabaseHas('organizations', ['code' => 'tennis_a', 'auth_code' => 'ABC123']);
    }

    /** 认证码大小写不敏感 */
    public function test_register_auth_code_case_insensitive(): void
    {
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'ABC123']);

        $this->post('/register', [
            'username' => 'xiaoming',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'organization_code' => 'tennis_a',
            'organization_auth_code' => 'abc123',
        ])->assertRedirect('/appoints');
        $this->assertAuthenticated();
    }

    /** 登录（机构 + 用户名 + 密码）/ 登出（登录不需要认证码） */
    public function test_login_and_logout(): void
    {
        $this->makeUser('xiaoming', 'tennis_a');

        $this->post('/login', [
            'organization_code' => 'tennis_a',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertRedirect('/appoints');
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    /** 登录校验：机构无效 / 所选机构与用户注册机构不一致 / 密码错误 */
    public function test_login_validates_organization(): void
    {
        $this->makeUser('xiaoming', 'tennis_a');

        // 机构不在 organizations 表内
        $this->post('/login', [
            'organization_code' => 'not-exists',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertSessionHasErrors('organization_code');
        $this->assertGuest();

        // 所选机构与用户注册时所属机构不一致（选错机构，防止进错工作区）
        $this->post('/login', [
            'organization_code' => 'tennis_b',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        // 机构正确但密码错误
        $this->post('/login', [
            'organization_code' => 'tennis_a',
            'username' => 'xiaoming',
            'password' => 'wrong-pass',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /** 机构初始化状态接口：未初始化返回预生成码，已初始化返回 null，未知机构 404 */
    public function test_organization_status_endpoint(): void
    {
        // 未初始化：default_code 为 6 位大写字母数字
        $this->getJson('/organizations/tennis_a/status')
            ->assertOk()
            ->assertJsonPath('initialized', false)
            ->assertJsonStructure(['initialized', 'default_code'])
            ->assertJson(fn ($json) => $json->where('initialized', false));

        $body = $this->getJson('/organizations/tennis_a/status')->json();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', (string) $body['default_code']);

        // 已初始化：default_code 为 null
        Organization::where('code', 'tennis_a')->update(['auth_code' => 'ABC123']);
        $this->getJson('/organizations/tennis_a/status')
            ->assertOk()
            ->assertJsonPath('initialized', true)
            ->assertJsonPath('default_code', null);

        // 未知机构 404
        $this->getJson('/organizations/not-exists/status')->assertNotFound();
    }

    /** 未登录访问页面跳登录页、访问 API 返回 401 */
    public function test_guest_redirected_to_login_and_api_returns_401(): void
    {
        $this->get('/appoints')->assertRedirect('/login');
        $this->getJson('/api/booking')->assertStatus(401);
        $this->postJson('/api/chat', ['message' => '你好'])->assertStatus(401);
    }

    /** 登录成功后 API 应可访问（回归：api 组无 session 中间件导致 401 → 前端反复跳登录闪刷新） */
    public function test_logged_in_user_can_call_api(): void
    {
        $this->makeUser('xiaoming', 'tennis_a');

        $this->post('/login', [
            'organization_code' => 'tennis_a',
            'username' => 'xiaoming',
            'password' => 'secret123',
        ])->assertRedirect('/appoints');

        // 登录态应通过 session 延续到 API 请求
        $this->getJson('/api/booking')->assertOk();
    }

    /** 跨机构数据不可见：A 机构约课 B 机构通过 Eloquent 与 API 均查不到 */
    public function test_cross_organization_data_invisible(): void
    {
        $userA = $this->makeUser('alice', 'tennis_a');
        $userB = $this->makeUser('bob', 'tennis_b');

        $booking = $this->makeBooking($userA);
        $this->assertSame('tennis_a', $booking->organization_code);

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
        $userA = $this->makeUser('alice', 'tennis_a');
        $userC = $this->makeUser('carol', 'tennis_a');

        $booking = $this->makeBooking($userA);

        $this->actingAs($userC);
        $this->assertNotNull(BookingRecord::find($booking->id));
        $this->assertSame(1, BookingRecord::count());
    }

    /** 聊天记录与生成图片同样按机构隔离，且创建时自动填充机构 */
    public function test_messages_and_images_isolated_by_org(): void
    {
        $userA = $this->makeUser('alice', 'tennis_a');
        $userB = $this->makeUser('bob', 'tennis_b');

        $this->actingAs($userA);
        $message = Message::create(['role' => 'user', 'type' => 'text', 'content' => '你好']);
        $image = GeneratedImage::create([
            'style_key' => 'a',
            'user_image' => 'uploads/demo.png',
            'output_image' => 'generated/out.png',
        ]);

        $this->assertSame('tennis_a', $message->organization_code);
        $this->assertSame('tennis_a', $image->organization_code);

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
        $userA = $this->makeUser('alice', 'tennis_a');
        $userB = $this->makeUser('bob', 'tennis_b');

        $this->actingAs($userA);
        $result = app(ExcelService::class)->generate();
        $filename = $result['filename'];

        $this->assertStringStartsWith('tennis_a_', $filename);

        // 同机构可下载
        $this->get('/api/excel/download/'.rawurlencode($filename))->assertOk();

        // 跨机构下载被拒（404）
        $this->actingAs($userB);
        $this->get('/api/excel/download/'.rawurlencode($filename))->assertNotFound();
    }

    /** 认证码生成：6 位、大写字母数字、不含易混淆字符 */
    public function test_generate_auth_code_format(): void
    {
        foreach (range(1, 10) as $_) {
            $code = Organization::generateAuthCode();
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $code);
        }
    }
}
