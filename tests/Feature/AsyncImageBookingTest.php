<?php

namespace Tests\Feature;

use App\Jobs\ProcessBookingImage;
use App\Models\Message;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DoubaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 截图约课异步化测试：图片消息派发队列任务并立即返回、文字消息保持同步、history 增量查询
 */
class AsyncImageBookingTest extends TestCase
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

    /** 带图消息：保存用户消息（含 user_id）→ 派发 ProcessBookingImage → 立即返回 async */
    public function test_image_message_dispatches_job_and_returns_async(): void
    {
        Queue::fake();
        Storage::fake('public');

        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $response = $this->post('/api/chat', [
            'message' => '帮我约课',
            'image' => UploadedFile::fake()->image('booking.png'),
        ]);

        $response->assertOk()
            ->assertJsonPath('async', true)
            ->assertJsonPath('reply', '收到！图片已提交后台处理，完成后会通知你。');

        // 用户消息已存档且带 user_id
        $message = Message::where('role', 'user')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame($user->id, $message->user_id);
        $this->assertSame('tennis_a', $message->organization_code);

        // 队列任务携带用户消息 id
        ProcessBookingImage::assertPushed(
            ProcessBookingImage::class,
            fn (ProcessBookingImage $job) => $job->messageId === $message->id,
        );
    }

    /** 文字闲聊（本地关键词未命中 + 轻量豆包判定无关）：直接回复指引文案，不调约课接口 */
    public function test_text_chitchat_replies_directly_without_booking_api(): void
    {
        Queue::fake();

        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->once()->with('你好')->andReturn(false);
            $mock->shouldReceive('parseBookingAction')->never();
        });

        $response = $this->postJson('/api/chat', ['message' => '你好']);

        $reply = '我是约课助手，只处理约课相关的事情哦（约课、改课、取消、查询课程/时间等）～';
        $response->assertOk()->assertJsonPath('reply', $reply);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => $reply]);

        ProcessBookingImage::assertNothingPushed();
    }

    /** 文字约课消息（本地关键词命中）：直接走约课解析，不调轻量豆包、不派发队列 */
    public function test_text_booking_message_skips_lightweight_check(): void
    {
        Queue::fake();

        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->never();
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'other',
                'reply' => '好的，收到！',
                'data' => [],
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '帮我约课，明天上午10点']);

        $response->assertOk()->assertJsonPath('reply', '好的，收到！');

        ProcessBookingImage::assertNothingPushed();
    }

    /** 本地关键词未命中但轻量豆包判定相关：放行约课解析 */
    public function test_text_related_via_lightweight_check_still_parses(): void
    {
        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('isBookingRelated')->once()->andReturn(true);
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'other',
                'reply' => '好的，收到！',
                'data' => [],
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '明天天气怎么样']);

        $response->assertOk()->assertJsonPath('reply', '好的，收到！');
    }

    /** DoubaoService::isBookingRelated 调用失败时保守返回 true（放行），不误拦约课 */
    public function test_lightweight_check_defaults_to_related_on_error(): void
    {
        Http::fake(['*' => Http::response('server error', 500)]);

        $this->assertTrue(app(DoubaoService::class)->isBookingRelated('你好'));
    }

    /** Job 异常兜底：恢复机构上下文后存档 assistant 错误消息 */
    public function test_job_archives_error_reply_on_failure(): void
    {
        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $userMessage = Message::create([
            'role' => 'user',
            'type' => 'image',
            'content' => '约课',
            'user_id' => $user->id,
        ]);

        // toJsonForAI 在 handleBookingChat 的 try 之外，抛出后由 Job 兜底捕获
        $this->mock(BookingService::class, function ($mock) {
            $mock->shouldReceive('toJsonForAI')->andThrow(new \RuntimeException('模拟异常'));
        });

        app(ProcessBookingImage::class, ['messageId' => $userMessage->id])->handle();

        // 错误回复已存档，且机构上下文已恢复（organization_code 自动填充）
        $this->assertDatabaseHas('messages', [
            'role' => 'assistant',
            'type' => 'text',
            'content' => '出错了：模拟异常',
            'organization_code' => 'tennis_a',
        ]);
    }

    /** history 带 after_id：只返回 id 更大的消息（升序） */
    public function test_history_after_id_returns_only_newer_messages(): void
    {
        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $m1 = Message::create(['role' => 'user', 'type' => 'text', 'content' => '第一条']);
        $m2 = Message::create(['role' => 'assistant', 'type' => 'text', 'content' => '第二条']);
        $m3 = Message::create(['role' => 'assistant', 'type' => 'text', 'content' => '第三条']);

        $response = $this->getJson('/api/messages?after_id='.$m2->id);

        $response->assertOk();
        $messages = $response->json('messages');
        $this->assertCount(1, $messages);
        $this->assertSame($m3->id, $messages[0]['id']);
        $this->assertSame('第三条', $messages[0]['content']);

        // after_id 传 0（或缺省）为初次加载：返回全部消息、保持正序
        $ids = array_column($this->getJson('/api/messages?limit=50')->json('messages'), 'id');
        $this->assertSame([$m1->id, $m2->id, $m3->id], $ids);
    }
}
