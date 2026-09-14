<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBookingImage;
use App\Models\BookingRecord;
use App\Models\GeneratedImage;
use App\Models\Message;
use App\Services\BookingService;
use App\Services\CrmService;
use App\Services\DoubaoService;
use App\Services\ExcelService;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /** 本地约课/CRM 强特征关键词：命中直接放行，无需再调轻量豆包确认 */
    private array $bookingKeywords = [
        '约课', '预约', '约一节', '约一次', '约个', '约了',
        '上课', '课表', '课程', '下课', '上完', '补课', '请假', '课时', '几节课', '上课时间',
        '教练', '老师', '学员', '学生',
        '取消', '删掉', '退掉', '改课', '换课', '调整', '推迟', '提前',
        '场地', '有空', '空闲', '还剩',
        // 固定场次：说"以后/每周/固定场"时改的是模板而不是单次记录
        '固定场', '固定', '每周', '每个星期', '以后都', '以后', '不拼',
        '打球', '羽毛球', '球场',
        // CRM：办卡 / 安排课时 / 会员到店报备
        '会员', '办卡', '办张', '月卡', '年卡', '次卡', '游泳卡', '健身卡', '续卡', '买卡', '办一张',
        '安排', '分配', '报名', '报个', '买课', '报课',
        '用了一次', '用一次', '来游了', '游了', '到店', '打卡', '用卡',
    ];

    public function __construct(
        private readonly DoubaoService $doubao,
        private readonly BookingService $booking,
        private readonly CrmService $crm,
        private readonly ExcelService $excel,
        private readonly FixedScheduleService $fixedSchedule,
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

            // 3. 截图约课（带图消息）→ 异步：先存档用户消息并派发队列任务，立即返回
            //    豆包识别与约课操作在后台完成，结果通过轮询推送到页面
            if ($hasImage) {
                ProcessBookingImage::dispatch($userMessage->id);

                return response()->json([
                    'reply' => '收到！图片已提交后台处理，完成后会通知你。',
                    'async' => true,
                    'user_message' => $this->messageToPayload($userMessage),
                ]);
            }

            // 4. 文字消息：先本地关键词预筛，未命中再走轻量豆包二分类。
            //    判定与约课无关则直接回复，不调用约课解析接口（避免闲聊消息白白等待 1 分钟+）
            if (! $this->isBookingRelated($text) && ! $this->doubao->isBookingRelated($text)) {
                $reply = '我是约课助手，只处理约课相关的事情哦（约课、改课、取消、查询课程/时间等）～';

                Message::create([
                    'role' => 'assistant',
                    'type' => 'text',
                    'content' => $reply,
                ]);

                return response()->json(['reply' => $reply]);
            }

            // 5. 文字约课 / 智能聊天（保持同步）
            $result = $this->handleBookingChat($userMessage, false);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('聊天处理异常', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            // 异常也要把回复存档；豆包侧异常已包装成友好提示，其他异常直接展示原始消息
            Message::create([
                'role' => 'assistant',
                'type' => 'text',
                'content' => $e->getMessage(),
            ]);

            return response()->json(['reply' => $e->getMessage()], 200);
        }
    }

    /**
     * 读取历史消息
     *
     * - 不带 after_id：初次加载，返回最新 N 条（内部倒序取再反转为正序）
     * - 带 after_id：增量查询，返回 id > after_id 的新消息（升序，供轮询使用）
     */
    public function history(Request $request): JsonResponse
    {
        $afterId = (int) $request->input('after_id', 0);

        // 聊天记录按登录用户隔离：只返回当前用户自己的消息（机构隔离由 OrganizationScope 负责）
        // 未登录（异常情况）直接返回空，避免 where('user_id', null) 把无归属的历史消息漏出去
        $userId = auth('web')->id();

        if (! $userId) {
            return response()->json(['messages' => []]);
        }

        if ($afterId > 0) {
            $messages = Message::where('user_id', $userId)
                ->where('id', '>', $afterId)
                ->orderBy('id', 'asc')
                ->limit(min((int) $request->input('limit', 100), 500))
                ->get()
                ->map(fn (Message $m) => $this->messageToPayload($m));
        } else {
            $messages = Message::where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->limit(min((int) $request->input('limit', 100), 500))
                ->get()
                ->reverse()
                ->values()
                ->map(fn (Message $m) => $this->messageToPayload($m));
        }

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

            $imageUrl = '/storage/'.$savedPath; // 相对路径：浏览器自动用当前访问域名，避免 APP_URL 配置错误导致图片加载不出
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

    /**
     * 本地关键词预筛：判断纯文字消息是否与约课相关（毫秒级，命中即放行）
     */
    private function isBookingRelated(string $text): bool
    {
        foreach ($this->bookingKeywords as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 约课 / 智能聊天处理（public 供队列 Job ProcessBookingImage 复用）
     */
    public function handleBookingChat(Message $userMessage, bool $hasImage): array
    {
        $text = $userMessage->content;

        // 截图识别：传本地文件路径，DoubaoService 会转成 base64 data URI 给视觉模型。
        // 不拼公网 URL —— 避免依赖 storage:link 软链 / APP_URL / 图片公网可达性（否则豆包服务端下载图片会 404）
        $imageRef = null;
        if ($hasImage && $userMessage->image_path) {
            $absolute = storage_path('app/public/'.$userMessage->image_path);
            $imageRef = is_file($absolute) ? $absolute : null;
        }

        $bookingsJson = $this->booking->toJsonForAI();

        // 学员/会员/教练名单 JSON 一并给豆包作上下文（办卡/安排课时/报备时参照）
        $crmJson = $this->crm->crmContextJson();

        // 每周固定场次 JSON：用户说"以后/每周/固定场"时需要按模板定位
        $fixedJson = $this->fixedSchedule->toJsonForAI();

        // 用豆包解析用户意图与结构化数据
        try {
            $parsed = $this->doubao->parseBookingAction($text, $imageRef, $bookingsJson, $crmJson, $fixedJson);
        } catch (\Throwable $e) {
            Log::error('约课解析失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            // 豆包侧异常已包装成用户友好提示，此处直接保存，不再拼接技术前缀
            $reply = $e->getMessage();

            // 异步截图约课：失败也要存档回复，否则前端轮询收不到任何提示（静默失败）
            // 同步文字消息由前端直接渲染返回值，无需重复入库
            if ($hasImage) {
                Message::create([
                    'role' => 'assistant',
                    'type' => 'text',
                    'content' => $reply,
                ]);
            }

            return ['reply' => $reply];
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

            // 教练姓氏批量改名（预约记录 / 固定场模板 / 学员档案）
            case 'rename_coach':
                $reply = $this->doRenameCoach($data);
                break;

            // CRM：办卡 / 安排课时 / 报备消耗（不触发约课 Excel 更新）
            case 'create_card':
                $reply = $this->doCreateCard($data);
                break;

            case 'arrange_lessons':
                $reply = $this->doArrangeLessons($data);
                break;

            case 'use_card':
                $reply = $this->doUseCard($data);
                break;

            default:
                // 模型没识别出意图（或只回一句「好的，收到！」）时兜底：
                // 「场地有没有空」这类问题本地按约课记录直接算，避免没有信息量的回复
                $reply = $this->safeQueryReply($text, (string) ($parsed['reply'] ?? ''), $bookingsJson);
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
        // 作用范围=以后每周都改 → 改的是固定场次模板，会影响之后所有未发生的周
        if ($this->isFutureScope($data)) {
            return $this->doUpdateFuture($data);
        }

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
        // 作用范围=以后每周都取消 → 停用固定场次模板，只取消未来未发生的记录
        if ($this->isFutureScope($data)) {
            return $this->doDeleteFuture($data);
        }

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

    /* -----------------------------------------------------------------
     | 内部：固定场次（scope=future）与教练改名
     | ----------------------------------------------------------------- */

    /**
     * 判断是否"以后每周都"作用范围（默认 once，只改这一次）
     */
    private function isFutureScope(array $data): bool
    {
        return strtolower(trim((string) ($data['scope'] ?? ''))) === 'future';
    }

    /**
     * scope=future 的修改：定位模板 → 改模板 → 重算未来未发生的记录
     */
    private function doUpdateFuture(array $data): string
    {
        $located = $this->fixedSchedule->locate($data);

        if ($located['need_info'] || ! $located['success'] || ! $located['template']) {
            return $located['message'];
        }

        $template = $located['template'];
        $newData = $this->buildFutureNewData($data);

        if (empty($newData)) {
            return '想把这个固定场次改成什么呢？例如：以后都改到 11 点，或者换到 2 号场地。';
        }

        $result = $this->fixedSchedule->syncFuture($template, $newData);
        $updated = $result['template']->fresh() ?? $result['template'];

        $message = '已更新固定场次「'.$updated->summary.'」：'
            .'删除未来 '.$result['deleted'].' 条、重新生成 '.$result['created'].' 条记录，'
            .'之后每周自动生效（已完成的课程保持不变）。';

        if ($result['conflicts']) {
            $message .= "\n其中 ".count($result['conflicts']).' 条因场地冲突未能生成，请检查：'
                ."\n".$this->formatConflicts($result['conflicts']);
        }

        return $message;
    }

    /**
     * scope=future 的删除：停用模板 + 取消未来未发生的记录
     */
    private function doDeleteFuture(array $data): string
    {
        $located = $this->fixedSchedule->locate($data);

        if ($located['need_info'] || ! $located['success'] || ! $located['template']) {
            return $located['message'];
        }

        $template = $located['template'];
        $summary = $template->summary;
        $result = $this->fixedSchedule->cancelFuture($template);

        return '已取消固定场次「'.$summary.'」，未来 '.$result['cancelled']
            .' 条记录已取消，之后不再自动生成（历史记录保留）。';
    }

    /**
     * 教练姓氏批量改名
     */
    private function doRenameCoach(array $data): string
    {
        $oldName = trim((string) ($data['old_name'] ?? ''));
        $newName = trim((string) ($data['new_name'] ?? ''));

        if ($oldName === '' || $newName === '') {
            return '请告诉我教练原来的名字和新的名字，例如：把孟改成孟宇。';
        }

        $count = $this->fixedSchedule->renameCoach($oldName, $newName);

        if ($count === 0) {
            return '没有找到教练「'.$oldName.'」的相关记录，请确认名字是否正确。';
        }

        return '已把教练「'.$oldName.'」改为「'.$newName.'」，共更新 '.$count.' 条记录（含约课记录、固定场次和学员档案）。';
    }

    /**
     * 组装 scope=future 的模板改动数据
     *
     * 用户说"改到 11 点"时豆包可能只给 new_data.start_at，这里同步推导 start_time/end_time。
     *
     * @return array<string, mixed>
     */
    private function buildFutureNewData(array $data): array
    {
        $raw = (array) ($data['new_data'] ?? []);
        $newData = [];

        foreach (['venue', 'coach_name', 'student_name', 'remark', 'start_time', 'end_time'] as $field) {
            if (! empty($raw[$field])) {
                $newData[$field] = trim((string) $raw[$field]);
            } elseif (! empty($data[$field]) && $field !== 'student_name') {
                $newData[$field] = trim((string) $data[$field]);
            }
        }

        // start_at 只在"改了时间"时作为推导依据，避免把某一天的具体日期写成模板时间
        if (! empty($raw['start_at'])) {
            try {
                $start = Carbon::parse((string) $raw['start_at']);
                $duration = (int) config('doubao.booking.duration_minutes', 60);

                $newData['start_time'] = $newData['start_time'] ?? $start->format('H:i');
                $newData['end_time'] = $newData['end_time'] ?? $start->copy()->addMinutes($duration)->format('H:i');
            } catch (\Throwable $e) {
                // 时间无法解析时忽略，仍按其它字段改模板
            }
        }

        return $newData;
    }

    /**
     * @param  array<int, array<string, string>>  $conflicts
     */
    private function formatConflicts(array $conflicts): string
    {
        return collect($conflicts)
            ->map(fn ($c) => '· '.$c['time'].' '.$c['venue'].' ↔ '.$c['conflict_with'])
            ->take(5)
            ->implode("\n");
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
     | 内部：CRM（办卡 / 安排课时 / 报备消耗）
     | ----------------------------------------------------------------- */

    private function doCreateCard(array $data): string
    {
        return $this->crm->createCard($data);
    }

    private function doArrangeLessons(array $data): string
    {
        return $this->crm->arrangeLessons($data);
    }

    private function doUseCard(array $data): string
    {
        return $this->crm->useCard($data);
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
            // general：复用 parseBookingAction 时豆包已生成的完整回答，避免第二次串行调用（省一半等待时间）；
            // 回答为空或只有「好的，收到！」时再兜底（场地空闲本地直接算）
            default => $this->safeQueryReply(
                trim((string) ($data['question'] ?? '')) !== '' ? (string) $data['question'] : $fallbackText,
                $aiReply,
                $bookingsJson
            ),
        };
    }

    private function queryCount(array $data): string
    {
        [$student, $coach] = $this->subject($data);
        if ($student === '' && $coach === '') {
            return '你想查谁上了几节课呢？告诉我学员或教练的名字吧。';
        }

        $count = $this->booking->countLessons($student, $coach);

        return $this->subjectLabel($student, $coach).'已经约了 '.$count.' 节课了。';
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
        [$from, $to] = $this->queryDateRange($data);

        // 用户问「所有/全部空闲场地」时 venue 为空：直接列全部场地，不再反问
        if ($venue === '') {
            return $this->allVenuesAvailability($from, $to);
        }

        return $this->formatAvailability($venue.' 场地', $this->booking->venueAvailability($venue, $from, $to));
    }

    /**
     * 全部场地的空闲时段（用户问「所有/全部空闲场地」时）
     */
    private function allVenuesAvailability(Carbon $from, Carbon $to): string
    {
        // 开发区整场（1/2，占用含 A+B 两个半场）排前面，再到半场与其它区域场地
        $venues = array_values(array_unique(array_merge(
            ['1', '1A', '1B', '2', '2A', '2B'],
            (array) config('doubao.booking.venues', [])
        )));

        $single = $from->isSameDay($to);
        $lines = [];

        foreach ($venues as $venue) {
            $days = $this->booking->venueAvailability($venue, $from, $to);

            if ($single) {
                $slots = $this->mergeSlots((array) ($days[0]['slots'] ?? []));
                $lines[] = '· '.$venue.'：'.($slots ? implode('、', $slots) : '全天无空闲');

                continue;
            }

            $lines[] = '· '.$venue.'：';
            foreach ($days as $day) {
                $slots = $this->mergeSlots((array) ($day['slots'] ?? []));
                $lines[] = '  '.$this->dayLabel(Carbon::parse($day['date'])).'：'
                    .($slots ? implode('、', $slots) : '无空闲');
            }
        }

        $head = $single
            ? $this->dayLabel($from).'各场地空闲时段'
            : $from->format('n月j日').'至'.$to->format('n月j日').'各场地空闲时段';

        return $head.'：'."\n".implode("\n", $lines);
    }

    /**
     * 日期标签：今天（9月14日 周一）/ 明天（9月15日 周二）/ 9月20日 周日
     */
    private function dayLabel(Carbon $day): string
    {
        $today = Carbon::today('Asia/Shanghai');
        $prefix = match (true) {
            $day->isSameDay($today) => '今天',
            $day->isSameDay($today->copy()->addDay()) => '明天',
            $day->isSameDay($today->copy()->addDays(2)) => '后天',
            default => '',
        };

        $weekday = '周'.['日', '一', '二', '三', '四', '五', '六'][(int) $day->dayOfWeek];

        return $prefix !== ''
            ? $prefix.'（'.$day->format('n月j日').' '.$weekday.'）'
            : $day->format('n月j日').' '.$weekday;
    }

    /**
     * 合并连续时段：08:00-09:00、09:00-10:00 → 08:00-10:00
     *
     * @param  array<int, string>  $slots
     * @return array<int, string>
     */
    private function mergeSlots(array $slots): array
    {
        $merged = [];

        foreach ($slots as $slot) {
            $parts = explode('-', (string) $slot);
            if (count($parts) !== 2) {
                continue;
            }

            $last = array_key_last($merged);
            if ($last !== null && $merged[$last][1] === $parts[0]) {
                $merged[$last][1] = $parts[1];

                continue;
            }

            $merged[] = [$parts[0], $parts[1]];
        }

        return array_map(fn (array $r) => $r[0].'-'.$r[1], $merged);
    }

    /**
     * 是否「有没有空」类问题（模型分类不可靠时也能答）
     */
    private function looksLikeAvailabilityQuestion(string $text): bool
    {
        if (preg_match('/空闲|有空|空场|可约|有哪些空|没课/u', $text) === 1) {
            return true;
        }

        return preg_match('/场地|球场|球馆|1A|1B|2A|2B|号场/u', $text) === 1
            && preg_match('/查询|查一下|查查|看看|情况|怎么样/u', $text) === 1;
    }

    /**
     * 从文本里猜场地：1A/1B/2A/2B →「场地1」「1号场」→ 其它区域场地
     */
    private function guessVenueFromText(string $text): string
    {
        if (preg_match('/(1A|1B|2A|2B)/iu', $text, $m) === 1) {
            return strtoupper($m[1]);
        }

        if (preg_match('/(?:场地|球场|号场)\s*([12])/u', $text, $m) === 1
            || preg_match('/([12])\s*号(?:场|场地)?/u', $text, $m) === 1) {
            return $m[1];
        }

        foreach (['龙安湖', '余之城', '一小', '信达', '教育学院'] as $venue) {
            if (mb_strpos($text, $venue) !== false) {
                return $venue;
            }
        }

        return '';
    }

    /**
     * 从文本里猜查询日期（默认今天到明天）
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function guessDateRange(string $text): array
    {
        $today = Carbon::today('Asia/Shanghai');

        if (str_contains($text, '后天')) {
            return [$today->copy()->addDays(2), $today->copy()->addDays(2)];
        }
        if (str_contains($text, '明天')) {
            return [$today->copy()->addDay(), $today->copy()->addDay()];
        }
        if (str_contains($text, '今天')) {
            return [$today->copy(), $today->copy()];
        }

        $nextWeek = str_contains($text, '下下周') ? 2 : (str_contains($text, '下周') ? 1 : 0);
        if (preg_match('/(?:周|星期)([一二三四五六日天1-7])/u', $text, $m) === 1) {
            $index = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '日' => 7, '天' => 7][$m[1]]
                ?? (int) $m[1];

            if ($index >= 1 && $index <= 7) {
                $day = $today->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($nextWeek)->addDays($index - 1);

                return [$day, $day];
            }
        }

        return [$today->copy(), $today->copy()->addDay()];
    }

    /**
     * 模型没给出有效回答时的兜底
     *
     * - 「场地有没有空」：本地按约课记录算，稳定、即时，不依赖模型分类
     * - 其它问题：让豆包基于约课数据直接答一次
     * - 都不行就沿用模型原话
     */
    private function safeQueryReply(string $text, string $aiReply, string $bookingsJson): string
    {
        $aiReply = trim($aiReply);
        $lame = $aiReply === ''
            || (mb_strlen($aiReply) <= 12 && preg_match('/^(好的|好嘞|收到|明白|了解|ok)/iu', $aiReply) === 1);

        if (! $lame) {
            return $aiReply;
        }

        // 问「场地有没有空」→ 本地按记录算；问教练的走下面的豆包问答（教练名本地不好猜）
        if ($this->looksLikeAvailabilityQuestion($text) && ! str_contains($text, '教练')) {
            [$from, $to] = $this->guessDateRange($text);
            $venue = $this->guessVenueFromText($text);

            return $venue !== ''
                ? $this->formatAvailability($venue.' 场地', $this->booking->venueAvailability($venue, $from, $to))
                : $this->allVenuesAvailability($from, $to);
        }

        try {
            return $this->doubao->answerQuery($text, $bookingsJson);
        } catch (\Throwable $e) {
            Log::warning('查询兜底失败', ['error' => $e->getMessage()]);

            return $aiReply !== '' ? $aiReply : '这条我没太理解，可以换个说法吗？（例如：1A 场地明天有空吗）';
        }
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
            $from = Carbon::today('Asia/Shanghai');
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
            $slots = $this->mergeSlots((array) ($day['slots'] ?? []));

            $lines[] = $slots
                ? $this->dayLabel($date).'空闲时段：'.implode('、', $slots)
                : $this->dayLabel($date).'没有空闲时段';
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
            'user_id' => auth('web')->id(),
        ]);
    }

    /**
     * 约课数据有变动时生成新 Excel
     */
    private function maybeGenerateExcel(string $intent): ?array
    {
        if (! in_array($intent, ['create', 'update', 'delete', 'complete', 'rename_coach'])) {
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
        return $this->booking->weeklyForApi()->toArray();
    }

    private function messageToPayload(Message $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'type' => $m->type,
            'content' => $m->content,
            'image_url' => $m->image_path ? '/storage/'.$m->image_path : null,
            'excel_url' => $m->extra['excel_url'] ?? null,
            'created_at' => $m->created_at?->format('H:i'),
        ];
    }
}
