<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DoubaoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 聊天查询的日期口径
 *
 * 用户文本里的日期词（今天/明天/后天/周X/本周X/下周X）以本地解析为准，
 * 即便模型把"本周三"算成 9月17日（周四），查到的也是正确的 9月16日（周三）。
 */
class ChatQueryDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 固定为 2026-09-14（周一）
        $this->travelTo(Carbon::parse('2026-09-14 17:00', 'Asia/Shanghai'));

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));
    }

    /** @param  array<string, mixed>  $data */
    private function mockDoubao(array $data): void
    {
        $this->mock(DoubaoService::class, function ($mock) use ($data) {
            $mock->shouldReceive('isBookingRelated')->andReturn(true);
            $mock->shouldReceive('parseBookingAction')->andReturn([
                'intent' => 'query',
                'data' => $data,
                'reply' => '',
            ]);
        });
    }

    /** 模型把"本周三"算错成 9月17日，服务端仍查 9月16日 */
    public function test_venue_availability_uses_the_date_word_in_the_user_text(): void
    {
        $this->mockDoubao([
            'query_type' => 'venue_availability',
            'venue' => '',
            'date_from' => '2026-09-17',   // 模型推算错误：这是周四
            'date_to' => '2026-09-17',
            'question' => '查询本周三的空闲场地',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '查询本周三的空闲场地']);

        $response->assertOk();

        $reply = (string) $response->json('reply');

        $this->assertStringContainsString('9月16日 周三', $reply);
        $this->assertStringNotContainsString('9月17日', $reply);
    }

    /** 教练空闲查询同様以本地日期为准 */
    public function test_coach_availability_uses_the_date_word_in_the_user_text(): void
    {
        $this->mockDoubao([
            'query_type' => 'coach_availability',
            'coach_name' => '王教练',
            'date_from' => '2026-09-23',   // 模型误把"周三"算成下周
            'date_to' => '2026-09-23',
            'question' => '王教练周三有空吗',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '王教练周三有空吗']);

        $response->assertOk();

        $this->assertStringContainsString('9月16日 周三', (string) $response->json('reply'));
    }

    /** 文本没提日期时才用模型给的日期 */
    public function test_falls_back_to_the_parsed_date_when_text_has_no_date_word(): void
    {
        $this->mockDoubao([
            'query_type' => 'venue_availability',
            'venue' => '1A',
            'date_from' => '2026-09-18',
            'date_to' => '2026-09-18',
            'question' => '1A 场地还能约吗',
        ]);

        $response = $this->postJson('/api/chat', ['message' => '1A 场地还能约吗']);

        $response->assertOk();

        $this->assertStringContainsString('9月18日 周五', (string) $response->json('reply'));
    }
}
