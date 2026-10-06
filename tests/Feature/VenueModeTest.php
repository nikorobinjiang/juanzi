<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\Organization;
use App\Models\User;
use App\Services\BookingService;
use App\Support\VenuePolicy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 机构场地模式：网球馆这类机构约课必须分配并校验场地，
 * 游泳馆 / 棋院这类机构不按场地排课（场地留空、不做场地冲突，只保留教练冲突与营业时段）。
 */
class VenueModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        VenuePolicy::flush();
    }

    protected function tearDown(): void
    {
        VenuePolicy::flush();

        parent::tearDown();
    }

    /** 造一个归属指定机构的账号并登录 */
    private function loginAs(string $orgCode, string $name = '王教练'): User
    {
        $user = User::create([
            'name' => $name,
            'username' => $name.'@'.$orgCode,
            'password' => 'secret123',
            'organization_code' => $orgCode,
            'role' => User::ROLE_USER,
        ]);

        $this->actingAs($user);

        return $user;
    }

    /** 明天上午 10 点（营业时段内） */
    private function at(int $hour = 10): string
    {
        return Carbon::tomorrow('Asia/Shanghai')->setTime($hour, 0)->format('Y-m-d H:i');
    }

    /** 迁移预置：游泳馆 / 棋院为不按场地，网球馆保持按场地 */
    public function test_migration_presets_none_mode_for_swim_and_qi_yuan(): void
    {
        $this->assertSame('none', Organization::where('code', 'swim_a')->value('venue_mode'));
        $this->assertSame('none', Organization::where('code', 'qi_yuan_a')->value('venue_mode'));
        $this->assertSame('required', Organization::where('code', 'tennis_a')->value('venue_mode'));
        // 网球馆B 早已被迁移删除，现存机构默认按场地排课
        $this->assertSame('required', Organization::where('code', 'alan_tennis')->value('venue_mode'));
    }

    /** 按场地的机构：不指定场地时自动分配空闲场地 */
    public function test_required_organization_auto_assigns_venue(): void
    {
        $this->loginAs('tennis_a');

        $first = app(BookingService::class)->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'start_at' => $this->at(),
        ]);

        $this->assertTrue($first['success']);
        $this->assertSame('1A', $first['booking']->venue);
        $this->assertStringContainsString('场地 1A', $first['message']);

        $second = app(BookingService::class)->create([
            'student_name' => '小红', 'coach_name' => '张教练', 'start_at' => $this->at(),
        ]);

        $this->assertTrue($second['success']);
        $this->assertNotSame($first['booking']->venue, $second['booking']->venue);
    }

    /** 按场地的机构：同一场地同一时间仍然冲突 */
    public function test_required_organization_still_detects_venue_conflict(): void
    {
        $this->loginAs('tennis_a');

        app(BookingService::class)->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'start_at' => $this->at(), 'venue' => '1A',
        ]);

        $conflict = app(BookingService::class)->create([
            'student_name' => '小红', 'coach_name' => '张教练', 'start_at' => $this->at(), 'venue' => '1A',
        ]);

        $this->assertFalse($conflict['success']);
        $this->assertNotNull($conflict['conflict']);
    }

    /** 不按场地的机构：不分配场地、场地留空，同一时间可以有多节课 */
    public function test_none_organization_books_without_venue(): void
    {
        $this->loginAs('swim_a');

        $first = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);

        $this->assertTrue($first['success']);
        $this->assertSame('', $first['booking']->venue);
        $this->assertStringNotContainsString('场地', $first['message']);

        $second = app(BookingService::class)->create([
            'student_name' => '小B', 'coach_name' => '陈教练', 'start_at' => $this->at(),
        ]);

        $this->assertTrue($second['success']);
        $this->assertSame('', $second['booking']->venue);
    }

    /** 不按场地的机构：即便调用方传了场地也静默忽略 */
    public function test_none_organization_ignores_given_venue(): void
    {
        $this->loginAs('swim_a');

        $result = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(), 'venue' => '1A',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['booking']->venue);
    }

    /** 不按场地的机构：教练冲突仍然生效 */
    public function test_none_organization_keeps_coach_conflict(): void
    {
        $this->loginAs('swim_a');

        $first = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);
        $this->assertTrue($first['success']);

        $conflict = app(BookingService::class)->create([
            'student_name' => '小B', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);

        $this->assertFalse($conflict['success']);
        $this->assertStringContainsString('教练冲突', $conflict['message']);
    }

    /** 不按场地的机构：营业时段仍然校验 */
    public function test_none_organization_keeps_opening_hours(): void
    {
        $this->loginAs('swim_a');

        $result = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(5),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('开放时间', $result['message']);
    }

    /** 不按场地的机构：改课不检测场地冲突，历史脏数据里的场地被清掉 */
    public function test_none_organization_update_skips_venue(): void
    {
        $this->loginAs('swim_a');

        $created = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);
        $booking = $created['booking'];

        // 模拟历史脏数据：这条记录里还留着场地
        $booking->venue = '1A';
        $booking->save();

        // 同一时段另有他人上课，不按场地的机构不该报场地冲突
        app(BookingService::class)->create([
            'student_name' => '小B', 'coach_name' => '陈教练', 'start_at' => $this->at(11),
        ]);

        $updated = app(BookingService::class)->update($booking->id, ['start_at' => $this->at(11)]);

        $this->assertTrue($updated['success']);
        $this->assertSame('', $booking->fresh()->venue);
        $this->assertStringNotContainsString('场地', $updated['message']);
    }

    /** 场地模式切换后立即生效：切为不按场地后约课不再分配场地 */
    public function test_switching_to_none_takes_effect_immediately(): void
    {
        $admin = User::create([
            'name' => 'root', 'username' => 'root', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin);

        $this->postJson('/api/admin/organization/venue-mode', ['venue_mode' => 'none'])
            ->assertOk()
            ->assertJsonPath('organization.venue_mode', 'none');

        $this->assertSame('none', Organization::where('code', 'tennis_a')->value('venue_mode'));

        $result = app(BookingService::class)->create([
            'student_name' => '小明', 'coach_name' => '王教练', 'start_at' => $this->at(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['booking']->venue);
    }

    /** 机构管理员只能改自己机构的场地模式 */
    public function test_org_admin_cannot_change_other_organization_mode(): void
    {
        $boss = User::create([
            'name' => 'boss', 'username' => 'boss', 'password' => 'secret123',
            'organization_code' => 'alan_tennis', 'role' => User::ROLE_ORG_ADMIN,
        ]);

        $this->actingAs($boss);

        $this->postJson('/api/admin/organization/venue-mode', ['org' => 'tennis_a', 'venue_mode' => 'none'])
            ->assertForbidden();

        $this->assertSame('required', Organization::where('code', 'tennis_a')->value('venue_mode'));

        $this->postJson('/api/admin/organization/venue-mode', ['org' => 'alan_tennis', 'venue_mode' => 'none'])
            ->assertOk();

        $this->assertSame('none', Organization::where('code', 'alan_tennis')->value('venue_mode'));
    }

    /** 非法场地模式直接拒绝 */
    public function test_invalid_venue_mode_is_rejected(): void
    {
        $admin = User::create([
            'name' => 'root', 'username' => 'root', 'password' => 'secret123',
            'organization_code' => 'tennis_a', 'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin);

        $this->postJson('/api/admin/organization/venue-mode', ['venue_mode' => 'court'])
            ->assertStatus(422);

        $this->assertSame('required', Organization::where('code', 'tennis_a')->value('venue_mode'));
    }

    /** 不按场地的机构：已有记录带场地时，删除 / 完成文案不出现空场地片段 */
    public function test_none_organization_messages_have_no_dangling_venue(): void
    {
        $this->loginAs('qi_yuan_a');

        $created = app(BookingService::class)->create([
            'student_name' => '小A', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);
        $booking = $created['booking'];

        $deleted = app(BookingService::class)->delete($booking->id);
        $this->assertTrue($deleted['success']);
        $this->assertStringNotContainsString('场地', $deleted['message']);
        $this->assertDatabaseMissing('booking_records', ['id' => $booking->id]);

        $again = app(BookingService::class)->create([
            'student_name' => '小B', 'coach_name' => '李教练', 'start_at' => $this->at(),
        ]);

        $done = app(BookingService::class)->complete($again['booking']->id);
        $this->assertTrue($done['success']);
        $this->assertStringNotContainsString('场地', $done['message']);
        $this->assertSame(BookingRecord::STATUS_COMPLETED, $again['booking']->fresh()->status);
    }
}
