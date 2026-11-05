<?php

namespace Tests\Feature;

use App\Models\FixedSchedule;
use App\Models\Organization;
use App\Models\ReminderLog;
use App\Models\WechatBinding;
use App\Models\BookingRecord;
use App\Models\Coach;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Services\LessonReminderService;
use App\Support\BookingWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 明日上课提醒：学员 / 教练两条流水线的渲染、去重、容错与机构隔离
 */
class LessonReminderTest extends TestCase
{
    use RefreshDatabase;

    /** 模板消息接口的响应；Http::fake 是追加而非覆盖，需要换响应当动态的只有一个 stub */
    private array $templateResponse = ['errcode' => 0, 'errmsg' => 'ok', 'msgid' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wechat.app_id', 'wxappid');
        config()->set('wechat.secret', 'wxsecret');
        config()->set('wechat.templates.booking_success.id', 'BOOK_TEMPLATE');
        config()->set('wechat.remind.student_enabled', true);
        config()->set('wechat.remind.coach_enabled', true);
        config()->set('wechat.remind.per_lesson', false);

        Organization::firstOrCreate(['code' => 'tennis_a'], ['name' => '网球馆A']);

        $this->fakeWechat();
    }

    /**
     * 打桩微信官方接口：token 与模板消息
     */
    private function fakeWechat(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'TOKEN_X',
                'expires_in' => 7200,
            ], 200),
            'api.weixin.qq.com/cgi-bin/message/template/send*' => function () {
                return Http::response($this->templateResponse, 200);
            },
        ]);
    }

    /**
     * 明天的某节课
     */
    private function makeBooking(array $overrides = []): BookingRecord
    {
        $day = BookingWindow::now()->addDay()->startOfDay();

        return BookingRecord::withoutGlobalScope(OrganizationScope::class)->create(array_merge([
            'organization_code' => 'tennis_a',
            'student_name' => '小明',
            'coach_name' => '王教练',
            'venue' => '1A',
            'start_at' => $day->copy()->setTime(9, 0),
            'end_at' => $day->copy()->setTime(10, 0),
            'status' => BookingRecord::STATUS_BOOKED,
        ], $overrides));
    }

    /**
     * 绑定某个 openid 到身份
     */
    private function makeBinding(string $openid, string $type, string $name, string $orgCode = 'tennis_a'): WechatBinding
    {
        $target = $type === WechatBinding::TYPE_COACH
            ? Coach::create(['organization_code' => $orgCode, 'name' => $name])
            : Student::create(['organization_code' => $orgCode, 'name' => $name]);

        return WechatBinding::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => $orgCode,
            'openid' => $openid,
            'target_type' => $type,
            'target_key' => WechatBinding::targetKey($type, (int) $target->id),
            'student_name' => $type === WechatBinding::TYPE_STUDENT ? $name : null,
            'coach_name' => $type === WechatBinding::TYPE_COACH ? $name : null,
            'enabled' => true,
        ]);
    }

    private function tomorrow(): Carbon
    {
        return BookingWindow::now()->addDay()->startOfDay();
    }

    /** 学员与教练各收到一条汇总卡片 */
    public function test_sends_one_card_to_student_and_one_to_coach(): void
    {
        $booking = $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');
        $this->makeBinding('OPENID_COACH', WechatBinding::TYPE_COACH, '王教练');

        $this->artisan('bookings:remind', ['--date' => $this->tomorrow()->toDateString()])
            ->assertSuccessful();

        Http::assertSentCount(3); // 1 次取 token + 2 次发消息

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'message/template/send')
            && $request['touser'] === 'OPENID_STU'
            && $request['template_id'] === 'BOOK_TEMPLATE'
            && str_contains($request['data']['thing9']['value'], '王教练')
            && str_contains($request['data']['time4']['value'], '09:00')
            && ! str_contains($request['data']['time4']['value'], '-'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'message/template/send')
            && $request['touser'] === 'OPENID_COACH'
            && $request['template_id'] === 'BOOK_TEMPLATE'
            && str_contains($request['data']['thing5']['value'], '小明'));

        $this->assertSame(2, ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('remind_date', $this->tomorrow()->toDateString())
            ->where('status', ReminderLog::STATUS_SENT)
            ->count());

        $this->assertSame((int) $booking->id, ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', 'OPENID_STU')
            ->first()
            ->booking_ids[0]);
    }

    /** 教练一天多节课只汇总成一条，卡片里带上总课时 */
    public function test_coach_multiple_lessons_are_merged_into_one_card(): void
    {
        $day = $this->tomorrow();
        $this->makeBooking(['start_at' => $day->copy()->setTime(9, 0), 'end_at' => $day->copy()->setTime(10, 0)]);
        $this->makeBooking(['start_at' => $day->copy()->setTime(14, 0), 'end_at' => $day->copy()->setTime(15, 0), 'student_name' => '小红']);
        $this->makeBinding('OPENID_COACH', WechatBinding::TYPE_COACH, '王教练');

        $this->artisan('bookings:remind', ['--only' => 'coach'])->assertSuccessful();

        Http::assertSentCount(2); // 1 次取 token + 1 条教练卡片

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'message/template/send')
            && $request['data']['thing1']['value'] === '网球馆A 2 节课'
            && str_contains($request['data']['time4']['value'], '09:00')
            && str_contains($request['data']['thing5']['value'], '小明')
            && ! isset($request['data']['remark']));
    }

    /** 同一天重复执行不会重复打扰用户 */
    public function test_second_run_is_skipped_by_idempotent_log(): void
    {
        $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $date = $this->tomorrow()->toDateString();
        $this->artisan('bookings:remind', ['--date' => $date])->assertSuccessful();
        Http::assertSentCount(2);

        $this->artisan('bookings:remind', ['--date' => $date])->assertSuccessful();

        // 第二次没有额外的模板消息请求，也不产生新的日志行
        Http::assertSentCount(2);
        $this->assertSame(1, ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', 'OPENID_STU')
            ->count());
    }

    /** --force 可以强制重发 */
    public function test_force_resends(): void
    {
        $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $date = $this->tomorrow()->toDateString();
        $this->artisan('bookings:remind', ['--date' => $date])->assertSuccessful();
        $this->artisan('bookings:remind', ['--date' => $date, '--force' => true])->assertSuccessful();

        Http::assertSentCount(3); // 取 token 一次（命中缓存）+ 两次发送
    }

    /** --per-lesson 时学员按每节课一条 */
    public function test_per_lesson_sends_one_card_each(): void
    {
        $day = $this->tomorrow();
        $this->makeBooking(['start_at' => $day->copy()->setTime(9, 0), 'end_at' => $day->copy()->setTime(10, 0)]);
        $this->makeBooking(['start_at' => $day->copy()->setTime(14, 0), 'end_at' => $day->copy()->setTime(15, 0)]);
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind', ['--per-lesson' => true])->assertSuccessful();

        $this->assertSame(2, ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', 'OPENID_STU')
            ->where('status', ReminderLog::STATUS_SENT)
            ->count());
    }

    /** openid 失效（取关）后停用绑定，避免每天浪费一次发送 */
    public function test_invalid_openid_disables_binding(): void
    {
        $this->templateResponse = ['errcode' => 43004, 'errmsg' => 'require subscribe'];

        $this->makeBooking();
        $binding = $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind')->assertFailed();

        $this->assertFalse((bool) $binding->fresh()->enabled);

        $this->assertDatabaseHas('reminder_logs', [
            'openid' => 'OPENID_STU',
            'status' => ReminderLog::STATUS_FAILED,
            'errcode' => '43004',
        ]);
    }

    /** 已取消的课不推送 */
    public function test_cancelled_lesson_is_not_reminded(): void
    {
        $this->makeBooking(['status' => BookingRecord::STATUS_CANCELLED]);
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind')->assertSuccessful();

        Http::assertSentCount(0);
    }

    /** 跨机构不串：A 机构的课程绝不推给 B 机构的绑定 */
    public function test_bindings_do_not_cross_organizations(): void
    {
        $this->makeBooking(['organization_code' => 'tennis_a']);
        $this->makeBinding('OPENID_B', WechatBinding::TYPE_STUDENT, '小明', 'tennis_b');

        $this->artisan('bookings:remind')->assertSuccessful();

        Http::assertSentCount(0);
    }

    /** 未配置模板 ID 时整条流水线静默跳过，不会空转也不会报错 */
    public function test_missing_template_id_skips_without_error(): void
    {
        config()->set('wechat.templates.booking_success.id', '');

        $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind')->assertSuccessful();

        Http::assertSentCount(0);
    }

    /** 同一个微信绑定两个孩子时合并成一条卡片 */
    public function test_parent_with_two_children_gets_single_card(): void
    {
        $day = $this->tomorrow();
        $this->makeBooking(['student_name' => '小明']);
        $this->makeBooking(['student_name' => '小红', 'start_at' => $day->copy()->setTime(14, 0), 'end_at' => $day->copy()->setTime(15, 0)]);
        $this->makeBinding('OPENID_PARENT', WechatBinding::TYPE_STUDENT, '小明');
        $this->makeBinding('OPENID_PARENT', WechatBinding::TYPE_STUDENT, '小红');

        $this->artisan('bookings:remind', ['--only' => 'student'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'message/template/send')
            && $request['touser'] === 'OPENID_PARENT'
            && str_contains($request['data']['time4']['value'], '09:00')
            && str_contains($request['data']['thing1']['value'], '共2节')
            && str_contains($request['data']['thing5']['value'], '小红'));

        $this->assertSame(1, ReminderLog::withoutGlobalScope(OrganizationScope::class)
            ->where('openid', 'OPENID_PARENT')
            ->count());
    }

    /** 学员名存在脏数据时用包含匹配兜底 */
    public function test_student_name_falls_back_to_contains_match(): void
    {
        $this->makeBooking(['student_name' => '小明同学']);
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind', ['--only' => 'student'])->assertSuccessful();

        Http::assertSentCount(2);
    }

    /** 推送前自动补齐固定场：没人打开页面，明天的固定课也不会漏推 */
    public function test_fixed_schedule_is_materialized_before_reminding(): void
    {
        $day = $this->tomorrow();
        $weekday = $day->dayOfWeek === 0 ? 7 : $day->dayOfWeek;

        FixedSchedule::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1A',
            'weekday' => $weekday,
            'start_time' => '18:30',
            'end_time' => '19:30',
            'student_name' => '小明',
            'coach_name' => '王教练',
            'is_student' => true,
            'active' => true,
            'effective_from' => BookingWindow::now()->startOfWeek(\Carbon\Carbon::MONDAY),
            'effective_to' => BookingWindow::now()->addWeeks(2),
        ]);

        // 不手工创建 booking_records：证明是命令在推送前补齐出来的
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind', ['--date' => $day->toDateString()])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'message/template/send')
            && $request['touser'] === 'OPENID_STU'
            && str_contains($request['data']['time4']['value'], '18:30')
            && ! str_contains($request['data']['time4']['value'], '-')
            && str_contains($request['data']['thing9']['value'], '王教练'));

        $this->assertSame(1, BookingRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('start_at', '>=', $day)
            ->where('start_at', '<', $day->copy()->addDay())
            ->count());
    }

    /** 预览模式不真正调用发送接口 */
    public function test_dry_run_does_not_call_send_api(): void
    {
        $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ReminderLog::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /** 调试用：--date 可以回看任意一天的课程 */
    public function test_date_option_targets_that_day(): void
    {
        $booking = $this->makeBooking();
        $this->makeBinding('OPENID_STU', WechatBinding::TYPE_STUDENT, '小明');

        $this->artisan('bookings:remind', ['--date' => $booking->start_at->copy()->subDay()->toDateString()])
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
