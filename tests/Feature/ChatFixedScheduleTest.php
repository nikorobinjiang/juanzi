<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\FixedSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use App\Models\User;
use App\Services\DoubaoService;
use App\Services\ExcelService;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 聊天里改固定场次：scope 区分"这一次"与"以后都改"
 *
 * - scope=once：只改/删这一条预约记录，模板不动
 * - scope=future：改/停用固定场次模板，只重算未发生的未来记录，历史记录保留
 * - rename_coach：教练姓氏批量改名（约课记录 / 模板 / 学员档案）
 */
class ChatFixedScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));

        $this->start = Carbon::now('Asia/Shanghai')->startOfWeek(Carbon::MONDAY)->addWeek();

        config([
            'doubao.fixed_schedule.start' => $this->start->format('Y-m-d'),
            'doubao.fixed_schedule.weeks' => 2,
        ]);
    }

    /** 用假豆包返回替换真实解析，同时避免生成 Excel 影响测试 */
    private function mockDoubao(array $parsed): void
    {
        $this->mock(DoubaoService::class, function ($mock) use ($parsed) {
            $mock->shouldReceive('isBookingRelated')->andReturn(true);
            $mock->shouldReceive('parseBookingAction')->andReturn($parsed);
        });

        $this->mock(ExcelService::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturn(null);
        });
    }

    /** 小明的周一 10:00 固定场 + 展开 2 周记录（第 1 周标记为已完成，模拟历史） */
    private function seedFixedSchedule(): FixedSchedule
    {
        $template = FixedSchedule::create([
            'organization_code' => 'tennis_a',
            'region' => FixedSchedule::REGION_DEVELOPMENT,
            'venue' => '1',
            'weekday' => 1,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'student_name' => '小明',
            'coach_name' => '张',
            'exclusive' => true,
            'is_student' => true,
        ]);

        app(FixedScheduleService::class)->materialize(2, 'tennis_a', $this->start);

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
        $this->assertCount(2, $records);

        $records->first()->update(['status' => BookingRecord::STATUS_COMPLETED]);

        return $template;
    }

    /** scope=future 取消：模板停用、未来记录取消，已完成的历史记录保留 */
    public function test_future_scope_cancels_template_and_future_records_only(): void
    {
        $template = $this->seedFixedSchedule();

        $this->mockDoubao([
            'intent' => 'delete',
            'data' => [
                'student_name' => '小明',
                'weekday' => 1,
                'start_time' => '10:00',
                'scope' => 'future',
            ],
            'reply' => '好的',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '以后每周一小明的固定场取消']);

        $response->assertOk();
        $this->assertStringContainsString('已取消固定场次', $response->json('reply'));

        $this->assertFalse($template->fresh()->active);

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
        $this->assertSame(BookingRecord::STATUS_COMPLETED, $records[0]->status);
        $this->assertSame(BookingRecord::STATUS_CANCELLED, $records[1]->status);
    }

    /** scope=future 改时间：只重算未来记录，模板时间同步更新，历史记录不动 */
    public function test_future_scope_reschedules_future_records(): void
    {
        $template = $this->seedFixedSchedule();

        $this->mockDoubao([
            'intent' => 'update',
            'data' => [
                'student_name' => '小明',
                'weekday' => 1,
                'start_time' => '10:00',
                'scope' => 'future',
                'new_data' => [
                    'start_time' => '11:00',
                    'start_at' => $this->start->copy()->setTime(11, 0)->format('Y-m-d H:i'),
                ],
            ],
            'reply' => '好的',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '以后小明周一10点改到11点']);

        $response->assertOk();
        $this->assertStringContainsString('已更新固定场次', $response->json('reply'));

        $this->assertSame('11:00', $template->fresh()->start_time);

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();

        // 已完成的历史记录保留 + 固定场窗口（本周 + 下周）内按新时间重算出的两条
        $this->assertCount(3, $records);

        // 历史记录仍是 10:00 且保持已完成
        $this->assertSame('10:00', $records[0]->start_at->format('H:i'));
        $this->assertSame(BookingRecord::STATUS_COMPLETED, $records[0]->status);

        // 未来记录已改到 11:00，且只重算固定场窗口内（不会多展开一周）
        $this->assertSame('11:00', $records[1]->start_at->format('H:i'));
        $this->assertSame($this->start->format('Y-m-d').' 11:00', $records[1]->start_at->format('Y-m-d H:i'));
        $this->assertSame('11:00', $records[2]->start_at->format('H:i'));
        $this->assertSame($this->start->copy()->addWeek()->format('Y-m-d').' 11:00', $records[2]->start_at->format('Y-m-d H:i'));
    }

    /** scope=once：只改这一次，模板与另一周的记录都不受影响 */
    public function test_once_scope_changes_single_record_only(): void
    {
        $template = $this->seedFixedSchedule();

        $this->mockDoubao([
            'intent' => 'update',
            'data' => [
                'student_name' => '小明',
                'start_at' => $this->start->copy()->setTime(10, 0)->format('Y-m-d H:i'),
                'scope' => 'once',
                'new_data' => [
                    'start_at' => $this->start->copy()->setTime(12, 0)->format('Y-m-d H:i'),
                ],
            ],
            'reply' => '好的',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '这周一小明不来了，改到12点']);

        $response->assertOk();

        // 模板未被修改
        $this->assertSame('10:00', $template->fresh()->start_time);

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->orderBy('start_at')->get();
        $this->assertCount(2, $records);
        $this->assertSame($this->start->copy()->format('Y-m-d').' 12:00', $records[0]->start_at->format('Y-m-d H:i'));
        $this->assertSame($this->start->copy()->addWeek()->format('Y-m-d').' 10:00', $records[1]->start_at->format('Y-m-d H:i'));
    }

    /** rename_coach：约课记录、固定场模板、学员档案三处一起改名 */
    public function test_rename_coach_updates_all_places(): void
    {
        $template = $this->seedFixedSchedule();

        BookingRecord::withoutGlobalScope(OrganizationScope::class)->update(['coach_name' => '孟']);
        $template->update(['coach_name' => '孟']);
        Student::create([
            'name' => '小明',
            'coach_name' => '孟',
            'lessons_total' => 0,
            'organization_code' => 'tennis_a',
        ]);

        $this->mockDoubao([
            'intent' => 'rename_coach',
            'data' => ['old_name' => '孟', 'new_name' => '孟宇'],
            'reply' => '好的',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '把孟改成孟宇']);

        $response->assertOk();
        $this->assertStringContainsString('孟宇', $response->json('reply'));

        $records = BookingRecord::withoutGlobalScope(OrganizationScope::class)->get();
        $this->assertTrue($records->every(fn ($r) => $r->coach_name === '孟宇'));
        $this->assertSame('孟宇', $template->fresh()->coach_name);
        $this->assertSame('孟宇', Student::first()->coach_name);
    }

    /** rename_coach 未命中：给出提示而不是静默成功 */
    public function test_rename_coach_without_match_returns_hint(): void
    {
        $this->mockDoubao([
            'intent' => 'rename_coach',
            'data' => ['old_name' => '不存在', 'new_name' => '张三'],
            'reply' => '好的',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '把不存在改成张三']);

        $response->assertOk();
        $this->assertStringContainsString('没有找到', $response->json('reply'));
    }
}
