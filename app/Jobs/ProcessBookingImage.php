<?php

namespace App\Jobs;

use App\Http\Controllers\ChatController;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 聊天页截图约课异步处理
 *
 * 用户发送截图后，chat() 仅保存用户消息并派发本 Job，立即返回"后台处理中"；
 * worker 中恢复用户登录态（使机构自动填充与全局作用域生效），再复用
 * ChatController::handleBookingChat() 完成截图识别、约课操作与回复存档。
 */
class ProcessBookingImage implements ShouldQueue
{
    use Queueable;

    /** 豆包失败重试无意义，落 failed_jobs 排查即可 */
    public int $tries = 1;

    /** 豆包识别截图最长 90 秒 + 约课操作余量，须大于 worker 默认 60 秒，避免超时被杀 */
    public int $timeout = 240;

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        // 队列 worker 中 auth('web') 为 null，全局作用域不生效，可直接按 id 查询
        $message = Message::find($this->messageId);

        if (! $message || ! $message->user_id) {
            return;
        }

        // 恢复用户登录态，使 Message/BookingRecord 的机构自动填充与查询隔离生效
        Auth::guard('web')->loginUsingId($message->user_id);

        try {
            app(ChatController::class)->handleBookingChat($message, true);
        } catch (\Throwable $e) {
            Log::error('异步约课处理失败', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 沿用 chat() 的异常存档模式，保证用户能看到错误回复
            Message::create([
                'role' => 'assistant',
                'type' => 'text',
                'content' => '出错了：'.$e->getMessage(),
            ]);
        } finally {
            // 防止 worker 常驻进程残留登录态，污染后续任务
            Auth::guard('web')->logout();
        }
    }
}
