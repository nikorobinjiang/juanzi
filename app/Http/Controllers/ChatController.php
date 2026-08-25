<?php

namespace App\Http\Controllers;

use App\Models\BookingRecord;
use App\Models\GeneratedImage;
use App\Models\Message;
use App\Services\BookingService;
use App\Services\DoubaoService;
use App\Services\ExcelService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
                $reply = $this->handleQuery($data, $text, $bookingsJson, (string) ($parsed['reply'] ?? ''));
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

        $student = trim((string) ($data['student_name'] ?? ''));
        if ($student === '') {
            return '好的，请问是哪位学员约课呢？';
        }

        $result = $this->booking->create($data);

        if (! $result['success']) {
            return $result['message'];
        }

        return $result['message'].'，约课表已更新，快保存最新的 Excel 吧！';
    }

    private function doUpdate(array $data): string
    {
        $located = $this->booking->locateTarget($data);

        if ($located['need_info']) {
            return $located['message'];
        }
        if (! $located['success'] || ! $located['booking']) {
            return $located['message'];
        }

        $newData = array_filter(
            (array) ($data['new_data'] ?? $data),
            fn ($v) => $v !== null && $v !== ''
        );

        if (empty($newData)) {
            return '想怎么调整呢？例如：把时间改到明天下午2点，或换个场地。';
        }

        $result = $this->booking->update($located['booking']->id, $newData);

        return $result['success']
            ? $result['message'].'，约课表已更新！'
            : $result['message'];
    }

    private function doDelete(array $data): string
    {
        $located = $this->booking->locateTarget($data);

        if ($located['need_info']) {
            return $located['message'];
        }
        if (! $located['success'] || ! $located['booking']) {
            return $located['message'];
        }

        $result = $this->booking->delete($located['booking']->id);

        return $result['success'] ? $result['message'].'，约课表已更新！' : $result['message'];
    }

    private function doComplete(array $data): string
    {
        $located = $this->booking->locateTarget($data);

        if ($located['need_info']) {
            return $located['message'];
        }
        if (! $located['success'] || ! $located['booking']) {
            return $located['message'];
        }

        $target = $located['booking'];

        if ($target->status === BookingRecord::STATUS_COMPLETED) {
            return $target->student_name.' '.$target->start_at->format('n月j日 H:i').' 这节课已经标记完成啦，不用重复操作。';
        }

        $result = $this->booking->complete($target->id);

        return $result['success']
            ? $result['message'].'，表格已自动更新！'
            : $result['message'];
    }

    /* -----------------------------------------------------------------
     | 内部：查询分发（query 意图）
     | ----------------------------------------------------------------- */

    private function handleQuery(array $data, string $fallbackText, string $bookingsJson, string $aiReply = ''): string
    {
        $type = (string) ($data['query_type'] ?? 'general');

        return match ($type) {
            'count' => $this->queryCount($data),
            'last' => $this->queryLast($data),
            'next' => $this->queryNext($data),
            'schedule' => $this->querySchedule($data),
            'coach_availability' => $this->queryCoachAvailability($data),
            'venue_availability' => $this->queryVenueAvailability($data),
            // general：复用 parseBookingAction 时豆包已生成的完整回答，避免第二次串行调用（省一半等待时间）
            default => trim($aiReply) !== ''
                ? $aiReply
                : $this->doubao->answerQuery((string) ($data['question'] ?? $fallbackText), $bookingsJson),
        };
    }

    private function queryCount(array $data): string
    {
        [$student, $coach] = $this->subject($data);
        if ($student === '' && $coach === '') {
            return '你想查谁上了几节课呢？告诉我学员或教练的名字吧。';
        }

        $count = $this->booking->countCompletedLessons($student, $coach);

        return $this->subjectLabel($student, $coach).'已经上完 '.$count.' 节课了。';
    }

    private function queryLast(array $data): string
    {
        [$student, $coach] = $this->subject($data);
        if ($student === '' && $coach === '') {
            return '你想查谁上一次上课的时间呢？告诉我学员或教练的名字吧。';
        }

        $last = $this->booking->lastLesson($student, $coach);
        if (! $last) {
            return $this->subjectLabel($student, $coach).'还没有上过课记录。';
        }

        return $this->subjectLabel($student, $coach).'上一次课是 '.$last->start_at->format('n月j日 H:i')
            .'（'.$last->venue.' 场地 · 教练 '.$last->coach_name.'）。';
    }

    private function queryNext(array $data): string
    {
        [$student, $coach] = $this->subject($data);
        if ($student === '' && $coach === '') {
            return '你想查谁下一次上课的时间呢？告诉我学员或教练的名字吧。';
        }

        $next = $this->booking->nextLesson($student, $coach);
        if (! $next) {
            return $this->subjectLabel($student, $coach).'暂时没有已预约的课程。';
        }

        return $this->subjectLabel($student, $coach).'下一次课是 '.$next->start_at->format('n月j日 H:i')
            .'（'.$next->venue.' 场地 · 教练 '.$next->coach_name.'）。';
    }

    private function querySchedule(array $data): string
    {
        [$student, $coach] = $this->subject($data);
        if ($student === '' && $coach === '') {
            return '你想查谁的排课呢？告诉我学员或教练的名字吧。';
        }

        [$from, $to] = $this->queryDateRange($data);
        $list = $this->booking->schedule($student, $coach, $from, $to);

        if ($list->isEmpty()) {
            return $this->subjectLabel($student, $coach)
                .$from->format('n月j日').'至'.$to->format('n月j日').'没有查到课程安排。';
        }

        return $this->subjectLabel($student, $coach).'的课程安排：'."\n"
            .$list->map(fn (BookingRecord $b) => '· '.$b->start_at->format('n月j日 H:i')
                .'（'.$b->venue.' · 教练 '.$b->coach_name.' · '.$b->status_label.'）'
                .($b->remark !== '' ? ' · '.$b->remark : ''))
            ->implode("\n");
    }

    private function queryCoachAvailability(array $data): string
    {
        $coach = trim((string) ($data['coach_name'] ?? ''));
        if ($coach === '') {
            return '你想查哪位教练有没有空呢？告诉我教练的名字吧。';
        }

        [$from, $to] = $this->queryDateRange($data);

        return $this->formatAvailability('教练 '.$coach, $this->booking->coachAvailability($coach, $from, $to));
    }

    private function queryVenueAvailability(array $data): string
    {
        $venue = trim((string) ($data['venue'] ?? ''));
        if ($venue === '') {
            return '你想查哪个场地有没有空呢？（1A / 1B / 2A / 2B）';
        }

        [$from, $to] = $this->queryDateRange($data);

        return $this->formatAvailability($venue.' 场地', $this->booking->venueAvailability($venue, $from, $to));
    }

    /**
     * 提取学员/教练名（去掉两端空格）
     *
     * @return array{0: string, 1: string}
     */
    private function subject(array $data): array
    {
        return [
            trim((string) ($data['student_name'] ?? '')),
            trim((string) ($data['coach_name'] ?? '')),
        ];
    }

    /**
     * 学员/教练的称呼前缀（用于口语化回复）
     */
    private function subjectLabel(string $student, string $coach): string
    {
        if ($student !== '' && $coach !== '') {
            return $student.'（教练 '.$coach.'）';
        }
        if ($student !== '') {
            return '学员 '.$student;
        }

        return '教练 '.$coach;
    }

    /**
     * 解析查询日期范围，默认今天到明天
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function queryDateRange(array $data): array
    {
        try {
            $from = Carbon::parse((string) ($data['date_from'] ?? ''))->startOfDay();
        } catch (\Throwable $e) {
            $from = Carbon::today();
        }

        try {
            $to = Carbon::parse((string) ($data['date_to'] ?? ''))->startOfDay();
        } catch (\Throwable $e) {
            $to = $from->copy()->addDay();
        }

        if ($to->lt($from)) {
            $to = $from->copy()->addDay();
        }

        return [$from, $to];
    }

    /**
     * 把空闲时段数组格式化成口语化回复
     *
     * @param  array<int, array{date: string, slots: array<int, string>}>  $days
     */
    private function formatAvailability(string $who, array $days): string
    {
        $lines = [];

        foreach ($days as $day) {
            $date = Carbon::parse($day['date']);
            $label = $date->isToday() ? '今天' : $date->format('n月j日');

            $lines[] = $day['slots']
                ? $label.'空闲时段：'.implode('、', $day['slots'])
                : $label.'没有空闲时段';
        }

        return $who.'：'."\n".implode("\n", $lines);
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
