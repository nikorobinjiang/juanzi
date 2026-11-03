<?php

namespace Tests\Feature;

use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\WechatBinding;
use App\Support\BookingWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 微信绑定页：授权、手机号路径、手动路径、跨机构隔离与名单接口节流
 */
class WechatBindTest extends TestCase
{
    use RefreshDatabase;

    private const OPENID = 'OPENID_FAKE';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wechat.app_id', 'wxappid');
        config()->set('wechat.secret', 'wxsecret');
        config()->set('wechat.oauth.session_key', 'wechat_openid');

        // tennis_a / tennis_b 已由 create_organizations_table 迁移种入，无需重复创建

        Http::fake([
            'api.weixin.qq.com/sns/oauth2/*' => Http::response(['openid' => self::OPENID], 200),
        ]);
    }

    /**
     * 带已授权的 session 访问绑定页
     */
    private function open(string $role = 'student'): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['wechat_openid' => self::OPENID])->get('/wechat/bind?role='.$role);
    }

    private function makeStudent(string $name, ?string $phone = null, string $orgCode = 'tennis_a'): Student
    {
        return Student::create([
            'organization_code' => $orgCode,
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    /** 没有 openid 时被送去微信网页授权 */
    public function test_redirects_to_oauth_without_openid(): void
    {
        $response = $this->get('/wechat/bind');

        $response->assertRedirect();
        $this->assertStringContainsString('open.weixin.qq.com/connect/oauth2/authorize', $response->headers->get('Location'));
    }

    /** 带 code 回来时能换到 openid 并落到 session */
    public function test_authorization_code_is_exchanged_for_openid(): void
    {
        $response = $this->get('/wechat/bind?code=CODE_X');

        $response->assertOk();
        $response->assertSee('约刻');
        $this->assertSame(self::OPENID, session('wechat_openid'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sns/oauth2/access_token'));
    }

    /** 手机号直接命中档案即完成绑定 */
    public function test_phone_binds_matching_students(): void
    {
        $this->makeStudent('小明', '13800001111');
        $this->makeStudent('小红', '13800001111'); // 同一个家长的第二个孩子

        $response = $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'student',
            'phone' => '13800001111',
            'organization_code' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $this->assertSame(2, WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', self::OPENID)
            ->count());
    }

    /** 手机号在多家机构都存在时不允许自动归属，必须选机构 */
    public function test_phone_across_organizations_requires_explicit_choice(): void
    {
        $this->makeStudent('小明', '13800002222', 'tennis_a');
        $this->makeStudent('小明', '13800002222', 'tennis_b');

        $response = $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'student',
            'phone' => '13800002222',
            'organization_code' => '',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(0, WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', self::OPENID)
            ->count());
    }

    /** 手机号匹配不上时给出提示，引导走手动选择 */
    public function test_unknown_phone_asks_for_manual_selection(): void
    {
        $response = $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'student',
            'phone' => '13900000000',
            'organization_code' => '',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    /** 手动路径：机构 + 学员姓名 */
    public function test_manual_selection_binds_student_by_name(): void
    {
        $this->makeStudent('小刚');

        $response = $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'student',
            'phone' => '',
            'organization_code' => 'tennis_a',
            'person' => '小刚',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('wechat_bindings', [
            'openid' => self::OPENID,
            'target_type' => WechatBinding::TYPE_STUDENT,
            'student_name' => '小刚',
            'source' => WechatBinding::SOURCE_MANUAL,
        ]);
    }

    /** 教练 Tab：手机号匹配 + 姓名归一 */
    public function test_coach_role_binds_coach(): void
    {
        Coach::create(['organization_code' => 'tennis_a', 'name' => '王明', 'phone' => '13600001111']);

        $response = $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'coach',
            'phone' => '13600001111',
            'organization_code' => '',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('wechat_bindings', [
            'openid' => self::OPENID,
            'target_type' => WechatBinding::TYPE_COACH,
            'coach_name' => '王明',
        ]);
    }

    /** 同一个微信可以同时持有学员和教练两种身份 */
    public function test_same_wechat_can_hold_both_roles(): void
    {
        $this->makeStudent('小明', '13800003333');
        Coach::create(['organization_code' => 'tennis_a', 'name' => '小明的教练', 'phone' => '13800003333']);

        $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'student', 'phone' => '13800003333', 'organization_code' => '',
        ])->assertSessionHasNoErrors();

        $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', [
            'role' => 'coach', 'phone' => '13800003333', 'organization_code' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', self::OPENID)
            ->count());
    }

    /** 重复绑定同一身份做 upsert，不产生重复行 */
    public function test_rebinding_same_person_updates_in_place(): void
    {
        $this->makeStudent('小明', '13800004444');

        $payload = ['role' => 'student', 'phone' => '13800004444', 'organization_code' => ''];

        $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', $payload);
        $this->withSession(['wechat_openid' => self::OPENID])->post('/wechat/bind', $payload);

        $this->assertSame(1, WechatBinding::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', self::OPENID)
            ->count());
    }

    /** 解绑只能解自己微信上的记录 */
    public function test_unbind_only_removes_own_binding(): void
    {
        $mine = WechatBinding::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => 'tennis_a',
            'openid' => self::OPENID,
            'target_type' => WechatBinding::TYPE_STUDENT,
            'target_key' => WechatBinding::targetKey(WechatBinding::TYPE_STUDENT, 1),
            'student_name' => '小明',
        ]);

        $others = WechatBinding::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => 'tennis_a',
            'openid' => 'OPENID_OTHER',
            'target_type' => WechatBinding::TYPE_STUDENT,
            'target_key' => WechatBinding::targetKey(WechatBinding::TYPE_STUDENT, 2),
            'student_name' => '小红',
        ]);

        $this->withSession(['wechat_openid' => self::OPENID])
            ->post('/wechat/unbind', ['binding_id' => $others->id]);

        $this->assertNotNull($others->fresh());

        $this->withSession(['wechat_openid' => self::OPENID])
            ->post('/wechat/unbind', ['binding_id' => $mine->id]);

        $this->assertNull($mine->fresh());
    }

    /** 名单接口按机构返回，且没有 openid 时拒绝 */
    public function test_names_endpoint_requires_openid_and_scope(): void
    {
        $this->makeStudent('小明', null, 'tennis_a');
        $this->makeStudent('陌生', null, 'tennis_b');

        $response = $this->get('/wechat/names?role=student&organization_code=tennis_a');
        $response->assertStatus(401);

        $response = $this->withSession(['wechat_openid' => self::OPENID])
            ->get('/wechat/names?role=student&organization_code=tennis_a');

        $response->assertOk();
        $response->assertJson(['names' => ['小明']]);
        $response->assertJsonMissing(['陌生']);
    }

    /** 绑定成功后页面展示已绑定身份与明日课程 */
    public function test_bound_page_shows_identity_and_tomorrow_lessons(): void
    {
        $student = $this->makeStudent('小明', '13800005555');
        WechatBinding::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => 'tennis_a',
            'openid' => self::OPENID,
            'target_type' => WechatBinding::TYPE_STUDENT,
            'target_key' => WechatBinding::targetKey(WechatBinding::TYPE_STUDENT, (int) $student->id),
            'student_name' => '小明',
        ]);

        $day = BookingWindow::now()->addDay()->startOfDay();
        \App\Models\BookingRecord::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => 'tennis_a',
            'student_name' => '小明',
            'coach_name' => '王教练',
            'venue' => '1A',
            'start_at' => $day->copy()->setTime(10, 0),
            'end_at' => $day->copy()->setTime(11, 0),
            'status' => \App\Models\BookingRecord::STATUS_BOOKED,
        ]);

        $response = $this->open();

        $response->assertOk();
        $response->assertSee('已绑定');
        $response->assertSee('小明');
        $response->assertSee('10:00');
        $response->assertSee('1A');
    }

    /** 未接线时给出明确提示而不是无限跳转授权 */
    public function test_unconfigured_wechat_shows_hint_instead_of_redirect_loop(): void
    {
        config()->set('wechat.app_id', '');
        config()->set('wechat.secret', '');

        $response = $this->get('/wechat/bind');

        $response->assertOk();
        $response->assertSee('尚未配置微信服务号');
    }
}
