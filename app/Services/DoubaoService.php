<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 豆包(火山方舟 Ark) 服务
 *
 * 封装三种能力：
 * 1. 对话补全   -> chatText() / chatJson()
 * 2. 图片生成   -> generateImage()
 * 3. 视觉识别   -> parseBookingAction() 内部使用
 */
class DoubaoService
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = (string) config('doubao.base_url');
        $this->apiKey = (string) config('doubao.api_key');
        $this->timeout = (int) config('doubao.timeout', 90);
    }

    /* -----------------------------------------------------------------
     | 基础请求
     | ----------------------------------------------------------------- */

    /**
     * 通用 chat completions 请求
     *
     * @param  array  $messages  标准 OpenAI 格式消息
     * @param  bool   $jsonMode  是否要求返回 JSON
     * @param  float  $temperature
     * @param  string|null  $model   覆盖模型（如视觉模型）
     * @return array  ['text' => string, 'raw' => array]
     */
    protected function chat(array $messages, bool $jsonMode = false, float $temperature = 0.3, ?string $model = null): array
    {
        $this->ensureApiKey();

        $payload = [
            'model' => $model ?: (string) config('doubao.chat_model'),
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::error('豆包接口连接/超时失败', ['error' => $e->getMessage()]);
            throw new RuntimeException('豆包接口响应超时，请稍后重试');
        }

        if ($response->failed()) {
            $body = (string) $response->body();
            $status = $response->status();
            Log::error('豆包对话接口失败', ['status' => $status, 'body' => $body]);

            $hint = match (true) {
                $status === 429 => '豆包接口当前繁忙，请稍后重试',
                $status === 404 && str_contains($body, 'InvalidEndpointOrModel') => '模型不存在或无权限：请在火山方舟控制台【模型广场】创建"推理接入点"，把得到的 ep-xxxxx 填到 .env 的 DOUBAO_CHAT_MODEL，或用当前有效模型ID',
                $status === 401 => 'API Key 无效：请检查 .env 的 DOUBAO_API_KEY',
                default => '豆包接口调用失败，请稍后重试',
            };

            throw new RuntimeException($hint);
        }

        $data = $response->json();

        return [
            'text' => $data['choices'][0]['message']['content'] ?? '',
            'raw' => $data,
        ];
    }

    /**
     * 文本对话，返回纯文本
     */
    public function chatText(string $system, string $user, float $temperature = 0.7): string
    {
        $res = $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], false, $temperature);

        return trim((string) $res['text']);
    }

    /**
     * 要求模型返回 JSON 对象，解析后返回数组
     */
    public function chatJson(string $system, string $user, float $temperature = 0.3): array
    {
        $res = $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], true, $temperature);

        $text = trim((string) $res['text']);

        // 容错：去掉可能包裹的 ```json ... ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            Log::warning('豆包返回非 JSON', ['text' => $text]);
            throw new RuntimeException('豆包返回内容无法解析为 JSON');
        }

        return $decoded;
    }

    /* -----------------------------------------------------------------
     | 约课消息解析（含聊天截图视觉识别）
     | ----------------------------------------------------------------- */

    /**
     * 解析用户消息 / 聊天截图，得到结构化约课动作
     *
     * @param  string  $userText
     * @param  string|null  $imageRef  聊天截图：本地文件绝对路径或可访问 URL（本地路径会自动转 base64 data URI）
     * @param  string  $bookingsJson  当前约课数据 JSON，用于上下文
     * @return array  ['intent' => ..., 'data' => [...], 'reply' => ...]
     */
    public function parseBookingAction(string $userText, ?string $imageRef, string $bookingsJson, string $crmJson = '', string $fixedJson = ''): array
    {
        $now = now('Asia/Shanghai')->format('Y-m-d H:i');

        // 当前登录用户即教练本人：显式告知豆包，保证"我/我的课"能解析到本人、
        // 未指定教练时也能填成本人（与 BookingService::create 的默认教练兜底保持一致）
        $currentUserName = trim((string) (auth('web')->user()?->name ?? ''));

        $currentUserBlock = $currentUserName === '' ? '' : "\n当前登录用户（教练）：“{$currentUserName}”\n"
            ."- “我 / 我的课 / 我什么时候上课 / 我的排课”等表述，一律按教练「{$currentUserName}」处理（query 的 schedule/count/last/next 把 coach_name 填为该姓名）\n"
            ."- create 意图：用户没有明确说出教练是谁时，coach_name 填「{$currentUserName}」；用户明确说了其他教练则以用户说的为准\n"
            ."- update / delete / complete：用户说“我的课”时，coach_name 填「{$currentUserName}」\n";

        $system = <<<PROMPT
你是羽毛球馆约课管理助手，负责把用户的自然语言(或聊天截图中的文字)解析成结构化动作。
当前时间：{$now}
{$currentUserBlock}

可执行动作 intent 仅限以下几种：
1. create    —— 约新课（出现学员、教练、上课时间，或"约课/约一节课"等）
2. update    —— 修改已有约课（出现"改/换/调整/提前/推迟"等）
3. delete    —— 取消/删除约课（出现"取消/删掉/退掉"等）
4. complete  —— 课程已完成（出现"上完了/上完课/下课了/结束"等）
5. query     —— 查询问题（询问上课时间、次数、空闲情况等）
6. create_card    —— 给会员办卡/买卡（出现"办卡/办…卡/月卡/年卡/次卡/游泳卡/健身卡/续卡"等）
7. arrange_lessons —— 给学员安排/购买课时、分配教练（如"给学员A和B安排王教练，10次课"，只登记课时包与当前教练，不含具体上课时间）
8. use_card       —— 会员到店使用了一次（出现"用了一次/来游了/今天游了1次/到店/打卡"，指会员卡消耗）
9. rename_coach  —— 批量修改教练姓名/姓氏（如"把孟改成孟宇""教练孟改成孟宇"，是对教练名的整体更正，不是改某节课）
10. other     —— 闲聊或其他无关内容

query 意图必须再细分 query_type（放在 data 中），规则如下：
- count    —— 统计上了几节课（如"上了几节课/上过多少次课/还剩几节课"，统计已完成课程）
- last     —— 上一次课是什么时候（如"上一次课/上次课/最近一次上课"）
- next     —— 下一次课是什么时候（如"下次课/下一次什么时候上课"）
- schedule —— 某学员/教练的排课安排（如"我什么时候上课/他这周有哪些课/课表"）
- coach_availability —— 教练空闲查询（如"张教练今天有空吗/明天有没有课"）
- venue_availability —— 场地空闲查询（如"1A场地明天有空吗"、"明天有哪些空场"；问"所有/全部场地"时 venue 留空，系统会列出所有场地）
- general  —— 其他开放问题（无法归入以上类型时）

你必须返回 JSON，格式如下：
{
  "intent": "create",
  "data": {
    "student_name": "学员姓名，缺失填空字符串",
    "coach_name": "教练姓名；用户未明确说出教练且有当前登录用户时填该用户姓名，否则填空字符串",
    "start_at": "上课开始时间，格式 Y-m-d H:i，必须是完整可计算的时间",
    "remark": "备注，没有则空字符串",
    "venue": "场地，开发区用 1A/1B/2A/2B（半场）或 1/2（整场 AB）；其它区域用 龙安湖/余之城/一小/信达/教育学院。用户指定才填，否则空字符串",
    "target_id": "始终填 0（不要尝试在约课记录里查找 id，系统会自动按学员/时间匹配定位）",
    "scope": "update/delete 时的作用范围：once=只改这一次（默认，用户说“这周X/下周X”“这一次”“今天”）；future=以后每周都改（用户说“以后都”“每次”“每周固定的”“固定场”“从这个月起”）。其余意图填空字符串",
    "weekday": "周一=1 … 周日=7（scope=future 或用户说每周几时填写，否则填 0）",
    "start_time": "固定场次的上课时间 H:i（scope=future 时填写，如 19:00），无则空字符串",
    "old_name": "rename_coach 时的原名/原姓氏（如“孟”）",
    "new_name": "rename_coach 时的新名/新姓氏（如“孟宇”）",
    "new_data": {},
    "question": "query 意图时用户的具体问题原文",
    "query_type": "query 意图时的子类型：count/last/next/schedule/coach_availability/venue_availability/general，非 query 意图填空字符串",
    "date_from": "查询起始日期 Y-m-d，默认今天",
    "date_to": "查询结束日期 Y-m-d，默认明天",
    "member_name": "会员姓名（create_card / use_card 时填写，其余为空字符串）",
    "phone": "手机号（办卡或建档时用户明确提到才填，否则空字符串）",
    "card_type": "会员卡类型 month/year/visits（create_card 时：月卡=month、年卡=year、N次卡=visits）",
    "total_count": "次卡总次数，整数（create_card 且 card_type=visits 时必须填写，如 20）",
    "student_names": "学员姓名数组，可多个（arrange_lessons 时填写，如 [\"A\",\"B\"]；只有一个人也用数组）",
    "lesson_count": "安排/购买课时节数，整数（arrange_lessons 时填写，如 10）",
    "use_time": "会员使用时间，缺省为空字符串（系统默认当前时间）"
  },
  "reply": "用一句话概括你理解到的操作（如：已识别到为小明约 2026-08-25 10:00 的课）；若 intent 为 query 且 query_type 为 general，则直接在此给出对用户问题的完整回答（见下方 query 参数规则）"
}

CRM 意图识别规则（非常重要，新增意图）：
- create_card（办卡）：出现"办卡/办月卡/办年卡/办次卡/办20次游泳卡/续卡"等表达时使用。示例："给钱多多办20次游泳卡，手机号13900000001" → card_type=visits、total_count=20、member_name=钱多多、phone=13900000001；"给吴芳办个年卡" → card_type=year；"帮郑小雨办月卡" → card_type=month。次卡必须解析出次数填 total_count；用户只说了"卡"没说类型时，按 user 用词判断（游泳卡/次卡/次数卡 → visits；月卡 → month；年卡 → year）。
- arrange_lessons（安排课时/分配教练）：出现"安排/分配/报名/报…节课/买…次课"且带教练与课时数、但没有具体上课时间。示例："给学员A和B安排王教练，10次课" → student_names=["A","B"]、coach_name=王教练、lesson_count=10。多个学员都要填进 student_names。
- use_card（会员报备消耗）：出现"XX用了一次/来游了一次/今天游了1次/到店用了一次/打卡"且对象是会员卡。示例："钱多多今天用了一次" → member_name=钱多多。注意与约课的 complete 区分：约课学员"上完课/下课了"属于 complete，只有会员/游泳卡消耗才用 use_card。
- 以上三种 CRM 意图一旦命中，不要返回 create/update/delete/query/complete。
- rename_coach（教练改名）：出现"把某教练的姓/名改成…"，且针对的是教练本人（不是某个学员、某一节课）时使用。示例："把孟改成孟宇" → old_name=孟、new_name=孟宇；"教练黎凯改叫黎凯文" → old_name=黎凯、new_name=黎凯文。仅当明确是在说教练时才用该意图，其余改名（学员改名）不要用。

固定场次与作用范围（scope）规则（非常重要）：
- 系统里除了"某一天的约课记录"，还有"每周固定场次"。用户的话里带"以后/每次/每周/固定场/每个月都"，说明要改的是固定场次，scope 必须填 future；否则 scope 填 once（只改这一次）。
- 示例："这周一小明不来了" → intent=delete、scope=once、student_name=小明、start_at=最近的那个周一该时间（系统只删这一次）
- 示例："以后每周一10点的小明固定场取消" → intent=delete、scope=future、student_name=小明、weekday=1、start_time=10:00
- 示例："以后小明周一10点改到11点" → intent=update、scope=future、student_name=小明、weekday=1、start_time=10:00、new_data={"start_time": "11:00", "start_at": "2026-09-21 11:00"}
- 示例："把小明以后都换到2号场地" → intent=update、scope=future、student_name=小明、new_data={"venue": "2"}
- scope=future 时：定位用 weekday（必填）+ start_time（尽量填）+ student_name（有就填），并在 new_data 里给出要改成的新值（改时间要同时给出 start_at 与 start_time）
- 判断不了是"这一次"还是"以后都"时，scope 填 once（改动最小、最安全）

时间解析规则（非常重要）：
- 用户说"今天/明天/后天/周X/几点"时，结合当前时间 {$now} 换算成具体日期时间
- 周X 按本周(周一为一周开始)处理；"下周X"按下周处理
- 上课默认 1 小时，无需输出 end_at
- 时间无法确定时 start_at 填空字符串，不要瞎编

query 意图的参数规则（非常重要）：
- 空闲查询（coach_availability/venue_availability）：date_from/date_to 默认今天到明天，用户提到具体日期再覆盖
- 计数/最近课程/排课查询：能提取到学员或教练就如实填，提取不到填空字符串，不要猜
- "教练什么时候有空"这类问题，教练姓名填到 coach_name；"场地有空"则场地名填到 venue
- 场地/教练是否空闲、有哪些空场，一律用 intent=query + query_type=venue_availability / coach_availability，不要归到 general，也不要给 create
- query 意图的 reply 必须写出结论（如"1A 明天 08:00-18:00 空闲"），禁止「好的，收到！」这类没有信息量的回复
- 场地查询同时支持半场 1A/1B/2A/2B 与整场 1/2，整场 1 = 1A+1B（整场被占用时 1A/1B 都不可约，反之亦然）；其它区域场地填 龙安湖/余之城/一小/信达/教育学院
- general 类型（开放问题/闲聊）：reply 字段直接给出完整、口语化的最终回答（1-3 句话，可引用约课 JSON 数据作答，不要编造），此时 reply 不是概括句而是最终答案

修改/取消/完成时的定位规则（非常重要）：
- 用户没说哪个学员、什么时间时（如只说"取消预约"），student_name 和 start_at 都填空字符串，不要猜，系统会提示用户补全
- 用户只说了学员没说时间，或只说了时间没说学员，就按用户说的如实填，不要擅自补充
- update 意图时：要改成的新内容放在 new_data 对象里（如 {"start_at": "2026-08-25 14:00"}、{"venue": "1A"}）；原记录由系统按学员/时间自动匹配，把原学员名、原时间如实填到 student_name/start_at 即可，不需要填 target_id
PROMPT;

        // 用户消息 = 文字 + 可能的截图
        $userContent = $userText !== '' ? $userText : '（本条为图片消息，请识别图片中的约课信息）';
        $userContent .= "\n\n以下是当前全部约课记录(JSON)：\n".$bookingsJson;
        if ($crmJson !== '') {
            $userContent .= "\n\n以下是当前学员/会员名单(JSON)，办卡、安排课时、报备消耗时可参考姓名与手机号：\n".$crmJson;
        }
        if ($fixedJson !== '') {
            $userContent .= "\n\n以下是当前每周固定场次(JSON)，用户说“以后/每周/固定场”时按这里的学员、星期与时间定位：\n".$fixedJson;
        }

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        if ($imageRef) {
            // 本地路径 -> base64 data URI（不依赖公网 URL）；http(s) URL 原样透传
            $imageUrl = $this->resolveImageRef($imageRef);

            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userContent],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ],
            ];
            $res = $this->chat($messages, true, 0.2, (string) config('doubao.vision_model'));
        } else {
            $messages[] = ['role' => 'user', 'content' => $userContent];
            $res = $this->chat($messages, true, 0.2);
        }

        $decoded = json_decode(trim((string) $res['text']), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('约课信息解析失败，请稍后重试');
        }

        return $decoded;
    }

    /**
     * 轻量意图过滤：判断文字消息是否与约课相关
     *
     * 用普通文本模式（非 JSON）快速二分类，比 parseBookingAction 的 JSON 模式快得多。
     * 仅当本地关键词未命中时调用；失败时保守放行（返回 true），避免误拦约课消息。
     */
    public function isBookingRelated(string $userText): bool
    {
        $system = <<<PROMPT
你是羽毛球馆约课助手的消息过滤器。判断用户消息是否与约课相关——
包括：约课、改课、取消、完成课程，查询课表、上课时间、剩余课时、教练/场地空闲，统计课时等；
也包括 CRM 事务：给会员办卡/月卡/年卡/次卡、给学员安排课时/分配教练、会员到店使用报备（用了一次/来游了一次）。
只回复一个单词：yes（相关）或 no（无关）。不要输出任何其他内容。
PROMPT;

        try {
            $answer = strtolower(trim($this->chatText($system, '用户消息：'.$userText, 0.0)));

            return str_contains($answer, 'yes');
        } catch (\Throwable $e) {
            Log::warning('轻量意图过滤失败，默认放行', ['error' => $e->getMessage()]);

            return true; // 保守放行，避免误拦约课
        }
    }

    /* -----------------------------------------------------------------
     | 约课问答
     | ----------------------------------------------------------------- */

    /**
     * 把约课数据传给豆包，回答用户的问题
     */
    public function answerQuery(string $question, string $bookingsJson): string
    {
        $system = <<<PROMPT
你是羽毛球馆约课管理助手。用户会提供约课记录 JSON，请你基于这些数据回答用户问题。
要求：
- 只依据提供的约课数据回答，不要编造
- 涉及具体时间时使用 Y-m-d H:i 格式，并换算成星期几
- 回答简洁、口语化、友好
- 如果数据不足，直接说明"目前没有查到相关约课记录"
PROMPT;

        return $this->chatText($system, "问题：{$question}\n\n约课记录：\n{$bookingsJson}", 0.4);
    }

    /* -----------------------------------------------------------------
     | 图片生成（风格模板 + 用户上传图 -> 结果图）
     | ----------------------------------------------------------------- */

    /**
     * 生成固定风格模板图片
     *
     * @param  string  $styleKey      风格 key（a / b）
     * @param  string  $userImagePath 用户上传图片的本地绝对路径
     * @return string  生成结果图保存到本地的相对路径（storage/app/public 下）
     */
    public function generateImage(string $styleKey, string $userImagePath): string
    {
        $this->ensureApiKey();

        $styles = (array) config('doubao.styles', []);
        $style = $styles[$styleKey] ?? null;

        if (! $style) {
            throw new RuntimeException("未知的图片风格：{$styleKey}");
        }

        $model = (string) config('doubao.image_model');
        $styleImageRef = $this->resolveImageRef($style['image'] ?? '');
        $userImageRef = $this->resolveImageRef($userImagePath);

        $styleName = $style['name'] ?? '图'.$styleKey;
        $styleDesc = $style['description'] ?? '该风格模板';
        $prompt = sprintf(
            "请将用户提供的照片转换成【%s】风格：%s。\n- 保留用户照片中的人物主体和面部特征\n- 背景与整体氛围完全按照风格模板图处理\n- 输出一张高质量成片",
            $styleName,
            $styleDesc
        );

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'image' => [$styleImageRef, $userImageRef],
            'response_format' => 'url',
        ];

        $imageTimeout = (int) config('doubao.image_timeout', 120);
        $response = Http::withToken($this->apiKey)
            ->connectTimeout(10)
            ->timeout($imageTimeout)
            ->post($this->baseUrl.'/images/generations', $payload);

        if ($response->failed()) {
            Log::error('豆包图片生成失败', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('图片生成失败：'.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        $data = $response->json();
        $url = $data['data'][0]['url'] ?? ($data['data'][0]['b64_json'] ?? null);

        if (! $url) {
            throw new RuntimeException('图片生成成功但未返回图片数据');
        }

        return $this->downloadToStorage($url);
    }

    /* -----------------------------------------------------------------
     | 内部工具
     | ----------------------------------------------------------------- */

    private function ensureApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('未配置 DOUBAO_API_KEY，请在 .env 中填写火山方舟的 API Key');
        }
    }

    /**
     * 把本地图片文件转成 base64 data URL，URL 直接返回
     *
     * 优先压缩再编码：手机截图动辄 2-5MB，base64 后 3-7MB，视觉模型处理大图非常慢、
     * 容易撞上超时。GD 可用时压缩到长边 1280、JPEG 质量 75，请求体可降到几百 KB。
     */
    private function resolveImageRef(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        if (! is_file($pathOrUrl)) {
            throw new RuntimeException("找不到图片文件：{$pathOrUrl}");
        }

        // 优先走 GD 压缩（输出统一 JPEG，体积小、视觉模型处理快）
        $compressed = $this->compressImage($pathOrUrl);
        if ($compressed !== null) {
            return 'data:image/jpeg;base64,'.base64_encode($compressed);
        }

        // 无 GD / 压缩失败：原样 base64（保留原格式）
        $mime = mime_content_type($pathOrUrl) ?: 'image/jpeg';
        $base64 = base64_encode((string) file_get_contents($pathOrUrl));

        return "data:{$mime};base64,{$base64}";
    }

    /**
     * 用 GD 压缩本地图片：长边超过 $maxEdge 缩放到 $maxEdge，输出 JPEG 质量 $quality。
     * 小图/无法解码/无 GD 扩展时返回 null（调用方走原样 base64 兜底）。
     */
    private function compressImage(string $path, int $maxEdge = 1280, int $quality = 75): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        [$w, $h] = $size;
        if ($w <= $maxEdge && $h <= $maxEdge) {
            return null; // 小图不需要压缩，保持原样
        }

        $scale = min($maxEdge / $w, $maxEdge / $h, 1.0);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return null;
        }

        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        $ok = imagejpeg($dst, null, $quality);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $ok ? ($data === false ? null : $data) : null;
    }

    /**
     * 下载图片到 storage/app/public/generated/
     */
    private function downloadToStorage(string $url): string
    {
        $dir = storage_path('app/public/generated');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = $this->guessExt($url);
        $filename = 'gen_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4)).$ext;
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        if (str_starts_with($url, 'data:')) {
            $raw = base64_decode(preg_replace('#^data:[^,]+;base64,#', '', $url));
        } else {
            $raw = Http::connectTimeout(10)->timeout((int) config('doubao.image_timeout', 120))->get($url)->body();
        }

        if (empty($raw)) {
            throw new RuntimeException('下载生成图片失败');
        }

        file_put_contents($path, $raw);

        return 'generated/'.$filename;
    }

    private function guessExt(string $url): string
    {
        if (preg_match('/\.(png|jpe?g|webp|gif)/i', parse_url($url, PHP_URL_PATH) ?? '', $m)) {
            return '.'.strtolower($m[1]);
        }

        return '.png';
    }
}
