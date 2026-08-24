<?php

namespace App\Http\Controllers;

use App\Models\GeneratedImage;
use App\Models\Message;
use App\Services\BookingService;
use App\Services\DoubaoService;
use App\Services\ExcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(
        private readonly DoubaoService $doubao,
        private readonly BookingService $booking,
        private readonly ExcelService $excel,
    ) {}

    /**
     * 聊天主入口
     *
     * 请求字段：
     * - message: 文本消息
     * - image:  上传的图片文件（生成图片功能的原图 / 约课聊天截图）
     * - style:  生成图片时选择的风格 a/b
     * - feature: image(生成图片) / booking(约课) / 留空走智能识别
     */
    public function chat(Request $request): JsonResponse
    {
        // 防止 PHP max_execution_time=30 把豆包请求杀死（FatalError 无法被捕获）
        set_time_limit(0);

        $text = trim((string) $request->input('message', ''));
        $style = strtolower(trim((string) $request->input('style', '')));
        $feature = strtolower(trim((string) $request->input('feature', '')));
        $hasImage = $request->hasFile('image');

        if ($text === '' && ! $hasImage) {
            return response()->json(['error' => '请发送文字或图片'], 422);
        }

        // 1. 保存用户消息
        $userMessage = $this->saveUserMessage($request, $text, $hasImage);

        try {
            // 2. 生成图片功能
            if ($feature === 'image' || ($hasImage && in_array($style, ['a', 'b']))) {
                $result = $this->handleImageGeneration($userMessage, $style);

                return response()->json($result);
            }

            // 3. 约课 / 智能聊天（图片视为聊天截图）
            $result = $this->handleBookingChat($userMessage, $hasImage);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('聊天处理异常', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            // 异常也要把回复存档
            Message::create([
                'role' => 'assistant',
                'type' => 'text',
                'content' => '出错了：'.$e->getMessage(),
            ]);

            return response()->json(['reply' => '出错了：'.$e->getMessage()], 200);
        }
    }

    /**
     * 读取历史消息（页面刷新时加载）
     */
    public function history(Request $request): JsonResponse
    {
        $messages = Message::orderBy('id', 'desc')
            ->limit(min((int) $request->input('limit', 100), 500))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => $this->messageToPayload($m));

        return response()->json(['messages' => $messages]);
    }

    /* -----------------------------------------------------------------
     | 内部：图片生成
     | ----------------------------------------------------------------- */

    private function handleImageGeneration(Message $userMessage, string $style): array
    {
        if (! in_array($style, ['a', 'b'])) {
            return ['reply' => '请选择图片风格：图A 或 图B'];
        }

        if (! $userMessage->image_path || ! is_file(storage_path('app/public/'.$userMessage->image_path))) {
            return ['reply' => '请先上传一张需要转换的照片'];
        }

        $absolute = storage_path('app/public/'.$userMessage->image_path);

        try {
            $savedPath = $this->doubao->generateImage($style, $absolute);

            GeneratedImage::create([
                'style_key' => $style,
                'user_image' => $userMessage->image_path,
                'output_image' => $savedPath,
            ]);

            $imageUrl = url('/storage/'.$savedPath);
            $styleName = config("doubao.styles.{$style}.name", '图'.$style);

            // 存档助手消息
            Message::create([
                'role' => 'assistant',
                'type' => 'image',
                'content' => '已用【'.$styleName.'】风格生成图片，已保存到本地。',
                'image_path' => $savedPath,
            ]);

            return [
                'reply' => '图片生成成功！已用【'.$styleName.'】风格生成并保存到本地。',
                'image' => ['url' => $imageUrl],
            ];
        } catch (\Throwable $e) {
            return ['reply' => '图片生成失败：'.$e->getMessage()];
        }
    }

    /* -----------------------------------------------------------------
     | 内部：约课 / 智能聊天
     | ----------------------------------------------------------------- */

    private function handleBookingChat(Message $userMessage, bool $hasImage): array
    {
        $text = $userMessage->content;

        // 截图识别：先把图片转成可访问 URL 给豆包视觉模型
        $imageUrl = null;
        if ($hasImage && $userMessage->image_path) {
            $imageUrl = url('/storage/'.$userMessage->image_path);
        }

        $bookingsJson = $this->booking->toJsonForAI();

        // 用豆包解析用户意图与结构化数据
        try {
            $parsed = $this->doubao->parseBookingAction($text, $imageUrl, $bookingsJson);
        } catch (\Throwable $e) {
            return ['reply' => '消息理解失败：'.$e->getMessage()];
        }

        $intent = $parsed['intent'] ?? 'other';
        $data = (array) ($parsed['data'] ?? []);
        $reply = '';

        switch ($intent) {
            case 'create':
                $reply = $this->doCreate($data);
                break;

            case 'update':
                $reply = $this->doUpdate($data);
                break;

            case 'delete':
                $reply = $this->doDelete($data);
                break;

            case 'complete':
                $reply = $this->doComplete($data);
                break;

            case 'query':
                $reply = $this->doubao->answerQuery(
                    (string) ($data['question'] ?? $text),
                    $bookingsJson
                );
                break;

            default:
                $reply = $parsed['reply'] ?? '好的，收到！';
        }

        // 生成最新 Excel（约课有变动时）
        $excelPayload = $this->maybeGenerateExcel($intent);

        // 存档助手消息
        Message::create([
            'role' => 'assistant',
            'type' => $excelPayload ? 'excel' : 'text',
            'content' => $reply,
            'extra' => $excelPayload ? ['excel_url' => $excelPayload['url']] : null,
        ]);

        return [
            'reply' => $reply,
            'excel' => $excelPayload,
            'weekly' => $this->bookingSummary(),
        ];
    }

    private function doCreate(array $data): string
    {
        $startAt = trim((string) ($data['start_at'] ?? ''));
        if ($startAt === '') {
            return '好的，请问学员是谁、约什么时候的课呢？（例如：给小明约明天上午10点）';
        }

        $result = $this->booking->create($data);

        if (! $result['success']) {
            return $result['message'];
        }

        return $result['message'].'，约课表已更新，快保存最新的 Excel 吧！';
    }

    private function doUpdate(array $data): string
    {
        $target = $this->booking->findTarget($data);

        if (! $target) {
            return '找不到要修改的约课记录，请提供学员姓名和原上课时间。';
        }

        $result = $this->booking->update($target->id, (array) ($data['new_data'] ?? $data));

        return $result['success']
            ? $result['message'].'，约课表已更新！'
            : $result['message'];
    }

    private function doDelete(array $data): string
    {
        $target = $this->booking->findTarget($data);

        if (! $target) {
            return '找不到要删除的约课记录，请提供学员姓名和上课时间。';
        }

        $result = $this->booking->delete($target->id);

        return $result['success'] ? $result['message'].'，约课表已更新！' : $result['message'];
    }

    private function doComplete(array $data): string
    {
        $target = $this->booking->findTarget($data);

        if (! $target) {
            return '找不到对应的约课记录，请告诉我哪个学员几点上完了课。';
        }

        $result = $this->booking->complete($target->id);

        return $result['success']
            ? $result['message'].'，表格已自动更新！'
            : $result['message'];
    }

    /* -----------------------------------------------------------------
     | 内部：工具
     | ----------------------------------------------------------------- */

    private function saveUserMessage(Request $request, string $text, bool $hasImage): Message
    {
        $imagePath = null;
        if ($hasImage) {
            $imagePath = $request->file('image')->store('uploads', 'public');
        }

        return Message::create([
            'role' => 'user',
            'type' => $hasImage ? 'image' : 'text',
            'content' => $text,
            'image_path' => $imagePath,
        ]);
    }

    /**
     * 约课数据有变动时生成新 Excel
     */
    private function maybeGenerateExcel(string $intent): ?array
    {
        if (! in_array($intent, ['create', 'update', 'delete', 'complete'])) {
            return null;
        }

        try {
            return $this->excel->generate();
        } catch (\Throwable $e) {
            Log::warning('Excel 生成失败', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 返回给前端的约课摘要（按周）
     */
    private function bookingSummary(): array
    {
        return $this->booking->weekly()
            ->map(fn (array $week) => [
                'label' => $week['label'],
                'count' => $week['items']->count(),
                'items' => $week['items']->map(fn ($b) => [
                    'id' => $b->id,
                    'student_name' => $b->student_name,
                    'coach_name' => $b->coach_name,
                    'start_at' => $b->start_at->format('Y-m-d H:i'),
                    'venue' => $b->venue,
                    'status' => $b->status,
                    'remark' => $b->remark,
                ])->values(),
            ])->values()
            ->toArray();
    }

    private function messageToPayload(Message $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'type' => $m->type,
            'content' => $m->content,
            'image_url' => $m->image_path ? url('/storage/'.$m->image_path) : null,
            'excel_url' => $m->extra['excel_url'] ?? null,
            'created_at' => $m->created_at?->format('H:i'),
        ];
    }
}
