<?php

namespace Tests\Feature;

use App\Jobs\ProcessBookingImage;
use App\Models\Message;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DoubaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /** 文字消息保持同步：不派发队列任务 */
    public function test_text_message_does_not_dispatch_job(): void
    {
        Queue::fake();

        $user = $this->makeUser('alice', 'tennis_a');
        $this->actingAs($user);

        $this->mock(DoubaoService::class, function ($mock) {
            $mock->shouldReceive('parseBookingAction')->once()->andReturn([
                'intent' => 'other',
                'reply' => '好的，收到！',
                'data' => [],
            ]);
        });

        $response = $this->postJson('/api/chat', ['message' => '你好']);

        $response->assertOk()->assertJsonPath('reply', '好的，收到！');

        ProcessBookingImage::assertNothingPushed();
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
