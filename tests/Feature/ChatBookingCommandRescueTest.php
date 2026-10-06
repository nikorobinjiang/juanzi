<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\User;
use App\Services\DoubaoService;
use App\Support\VenuePolicy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 约课指令被模型误判时的本地抢救
 *
 * 模型偶发会把「给小明约明天下午3点」判成 other / query(general)，
 * 原流程会把它丢给只会查记录的问答兜底，用户拿到「目前没有查到相关约课记录」且课没约上。
 * 这里覆盖：能救回来、救不回来（缺时间）不误建记录、正常查询不被当成约课。
 */
class ChatBookingCommandRescueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        VenuePolicy::flush();
        Storage::fake('public');

        $this->travelTo(Carbon::parse('2026-09-14 10:00', 'Asia/Shanghai'));

        $this->actingAs(User::create([
            'name' => 'coach_a',
            'username' => 'coach_a',
            'password' => 'secret123',
            'organization_code' => 'tennis_a',
        ]));
    }

    /**
     * mock 豆包：主解析返回 $parsed；$rescue 为 null 表示不允许调用抢救解析
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>|null  $rescue
     */
    private function mockDoubao(array $parsed, ?array $rescue): void
    {
        $this->mock(DoubaoService::class, function ($mock) use ($parsed, $rescue) {
            $mock->shouldReceive('isBookingRelated')->andReturn(true);
            $mock->shouldReceive('parseBookingAction')->andReturn($parsed);

            if ($rescue === null) {
                $mock->shouldReceive('parseBookingCreate')->never();
            } else {
                $mock->shouldReceive('parseBookingCreate')->once()->andReturn($rescue);
            }

            $mock->shouldReceive('answerQuery')->andReturn('目前没有查到相关约课记录');
        });
    }

    /** @return array<string, mixed> */
    private function parsedOther(): array
    {
        return ['intent' => 'other', 'data' => [], 'reply' => ''];
    }

    /** @return array<string, mixed> */
    private function rescuedData(array $override = []): array
    {
        return array_merge([
            'student_name' => '小明',
            'coach_name' => 'coach_a',
            'start_at' => '2026-09-15 15:00',
            'venue' => '',
            'remark' => '',
        ], $override);
    }

    /** 模型判成 other：本地识别为约课指令后救回 create，课正常约上 */
    public function test_rescues_booking_when_model_returns_other(): void
    {
        $this->mockDoubao($this->parsedOther(), $this->rescuedData());

        $response = $this->postJson('/api/chat', ['message' => '给小明约明天下午3点']);

        $response->assertOk();
        $this->assertStringContainsString('约课成功', (string) $response->json('reply'));
        $this->assertStringNotContainsString('没有查到', (string) $response->json('reply'));
        $this->assertSame(1, BookingRecord::count());
        $this->assertSame('小明', BookingRecord::first()?->student_name);
    }

    /** 模型判成 query(general)：同样救回 create */
    public function test_rescues_booking_when_model_returns_general_query(): void
    {
        $this->mockDoubao([
            'intent' => 'query',
            'data' => ['query_type' => 'general', 'question' => '给小明约明天下午3点'],
            'reply' => '',
        ], $this->rescuedData());

        $response = $this->postJson('/api/chat', ['message' => '给小明约明天下午3点']);

        $response->assertOk();
        $this->assertStringContainsString('约课成功', (string) $response->json('reply'));
        $this->assertSame(1, BookingRecord::count());
    }

    /** 疑问句（查空闲）不能被当成约课：不调抢救解析，也不建记录 */
    public function test_question_is_not_treated_as_booking_command(): void
    {
        $this->mockDoubao($this->parsedOther(), null);

        $response = $this->postJson('/api/chat', ['message' => '场地明天下午3点有空吗']);

        // 走了本地空闲查询，而不是约课
        $response->assertOk();
        $this->assertStringContainsString('9月15日', (string) $response->json('reply'));
        $this->assertStringNotContainsString('约课成功', (string) $response->json('reply'));
        $this->assertSame(0, BookingRecord::count());
    }

    /** 抢救解析拿不到上课时间时交还原兜底，不会凭空建记录 */
    public function test_rescue_without_start_at_does_not_create_booking(): void
    {
        $this->mockDoubao($this->parsedOther(), $this->rescuedData(['start_at' => '']));

        $response = $this->postJson('/api/chat', ['message' => '给小明约明天下午3点']);

        $response->assertOk();
        $this->assertStringContainsString('没有查到', (string) $response->json('reply'));
        $this->assertSame(0, BookingRecord::count());
    }

    /** 模型本来就判成 create：不再额外调一次抢救解析，避免多花一轮模型时间 */
    public function test_normal_create_does_not_trigger_rescue(): void
    {
        $this->mockDoubao([
            'intent' => 'create',
            'data' => $this->rescuedData(),
            'reply' => '已识别到为小明约 2026-09-15 15:00 的课',
        ], null);

        $response = $this->postJson('/api/chat', ['message' => '给小明约明天下午3点']);

        $response->assertOk();
        $this->assertStringContainsString('约课成功', (string) $response->json('reply'));
        $this->assertSame(1, BookingRecord::count());
    }
}
