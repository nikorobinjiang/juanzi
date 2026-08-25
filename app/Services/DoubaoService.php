<?php

namespace App\Services;

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

        $response = Http::withToken($this->apiKey)
            ->connectTimeout(10)
            ->timeout($this->timeout)
            ->post($this->baseUrl.'/chat/completions', $payload);

        if ($response->failed()) {
            $body = (string) $response->body();
            Log::error('豆包对话接口失败', ['status' => $response->status(), 'body' => $body]);

            $hint = '';
            if ($response->status() === 404 && str_contains($body, 'InvalidEndpointOrModel')) {
                $hint = '（模型不存在或无权限：请在火山方舟控制台【模型广场】创建"推理接入点"，把得到的 ep-xxxxx 填到 .env 的 DOUBAO_CHAT_MODEL，或用当前有效模型ID）';
            } elseif ($response->status() === 401) {
                $hint = '（API Key 无效：请检查 .env 的 DOUBAO_API_KEY）';
            }

            throw new RuntimeException('豆包接口调用失败：'.$response->status().' '.mb_substr($body, 0, 300).$hint);
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
     * @param  string|null  $imageUrl  聊天截图（可访问的 URL）
     * @param  string  $bookingsJson  当前约课数据 JSON，用于上下文
     * @return array  ['intent' => ..., 'data' => [...], 'reply' => ...]
     */
    public function parseBookingAction(string $userText, ?string $imageUrl, string $bookingsJson): array
    {
        $now = now()->format('Y-m-d H:i');

        $system = <<<PROMPT
你是羽毛球馆约课管理助手，负责把用户的自然语言(或聊天截图中的文字)解析成结构化动作。
当前时间：{$now}

可执行动作 intent 仅限以下几种：
1. create    —— 约新课（出现学员、教练、上课时间，或"约课/约一节课"等）
2. update    —— 修改已有约课（出现"改/换/调整/提前/推迟"等）
3. delete    —— 取消/删除约课（出现"取消/删掉/退掉"等）
4. complete  —— 课程已完成（出现"上完了/上完课/下课了/结束"等）
5. query     —— 查询问题（询问上课时间、次数、空闲情况等）
6. other     —— 闲聊或其他无关内容

query 意图必须再细分 query_type（放在 data 中），规则如下：
- count    —— 统计上了几节课（如"上了几节课/上过多少次课/还剩几节课"，统计已完成课程）
- last     —— 上一次课是什么时候（如"上一次课/上次课/最近一次上课"）
- next     —— 下一次课是什么时候（如"下次课/下一次什么时候上课"）
- schedule —— 某学员/教练的排课安排（如"我什么时候上课/他这周有哪些课/课表"）
- coach_availability —— 教练空闲查询（如"张教练今天有空吗/明天有没有课"）
- venue_availability —— 场地空闲查询（如"1A场地明天有空吗"）
- general  —— 其他开放问题（无法归入以上类型时）

你必须返回 JSON，格式如下：
{
  "intent": "create",
  "data": {
    "student_name": "学员姓名，缺失填空字符串",
    "coach_name": "教练姓名，缺失填空字符串",
    "start_at": "上课开始时间，格式 Y-m-d H:i，必须是完整可计算的时间",
    "remark": "备注，没有则空字符串",
    "venue": "场地，1A/1B/2A/2B，用户指定才填，否则空字符串",
    "target_id": "修改/删除/完成时尽量填对应的约课记录 id，找不到则填空",
    "new_data": {},
    "question": "query 意图时用户的具体问题原文",
    "query_type": "query 意图时的子类型：count/last/next/schedule/coach_availability/venue_availability/general，非 query 意图填空字符串",
    "date_from": "查询起始日期 Y-m-d，默认今天",
    "date_to": "查询结束日期 Y-m-d，默认明天"
  },
  "reply": "用一句话概括你理解到的操作（如：已识别到为小明约 2026-08-25 10:00 的课）；若 intent 为 query 且 query_type 为 general，则直接在此给出对用户问题的完整回答（见下方 query 参数规则）"
}

时间解析规则（非常重要）：
- 用户说"今天/明天/后天/周X/几点"时，结合当前时间 {$now} 换算成具体日期时间
- 周X 按本周(周一为一周开始)处理；"下周X"按下周处理
- 上课默认 1 小时，无需输出 end_at
- 时间无法确定时 start_at 填空字符串，不要瞎编

query 意图的参数规则（非常重要）：
- 空闲查询（coach_availability/venue_availability）：date_from/date_to 默认今天到明天，用户提到具体日期再覆盖
- 计数/最近课程/排课查询：能提取到学员或教练就如实填，提取不到填空字符串，不要猜
- "教练什么时候有空"这类问题，教练姓名填到 coach_name；"场地有空"则场地名填到 venue
- general 类型（开放问题/闲聊）：reply 字段直接给出完整、口语化的最终回答（1-3 句话，可引用约课 JSON 数据作答，不要编造），此时 reply 不是概括句而是最终答案

修改/取消/完成时的定位规则（非常重要）：
- 用户没说哪个学员、什么时间时（如只说"取消预约"），student_name 和 start_at 都填空字符串，不要猜，系统会提示用户补全
- 用户只说了学员没说时间，或只说了时间没说学员，就按用户说的如实填，不要擅自补充
- update 意图时：要改成的新内容放在 new_data 对象里（如 {"start_at": "2026-08-25 14:00"}、{"venue": "1A"}）；原记录用 target_id 定位，没有 id 则用原学员/原时间定位
PROMPT;

        // 用户消息 = 文字 + 可能的截图
        $userContent = $userText !== '' ? $userText : '（本条为图片消息，请识别图片中的约课信息）';
        $userContent .= "\n\n以下是当前全部约课记录(JSON)：\n".$bookingsJson;

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        if ($imageUrl) {
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
     */
    private function resolveImageRef(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        if (! is_file($pathOrUrl)) {
            throw new RuntimeException("找不到图片文件：{$pathOrUrl}");
        }

        $mime = mime_content_type($pathOrUrl) ?: 'image/jpeg';
        $base64 = base64_encode(file_get_contents($pathOrUrl));

        return "data:{$mime};base64,{$base64}";
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
