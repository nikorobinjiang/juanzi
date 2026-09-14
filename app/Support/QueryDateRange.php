<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * 自然语言日期词 → 具体日期（单一事实来源）
 *
 * 模型自己推算星期会出错（实测把"本周三" 9月16日 算成 9月17日 周四），
 * 所以只要用户文本里出现明确的日期词，就以这里本地算出的日期为准，
 * 不再用模型给的 date_from / date_to。
 *
 * 支持：今天/今日、明天/明日、后天、大后天、周X、星期X、本周X、这周X、下周X、下下周X
 * 一周以周一为第一天（与 BookingWindow、ExcelService 的口径一致）。
 */
final class QueryDateRange
{
    /** 星期中文/数字 → ISO 序号（周一=1 … 周日=7） */
    private const WEEKDAY_INDEX = [
        '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '日' => 7, '天' => 7,
        '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
    ];

    /** 相对今天的固定偏移天数 */
    private const DAY_OFFSETS = [
        '今天' => 0,
        '今日' => 0,
        '明天' => 1,
        '明日' => 1,
        '后天' => 2,
        '大后天' => 3,
    ];

    /**
     * 文本里的所有日期词，按出现顺序返回
     *
     * "查询本周三的空闲场地"            → [2026-09-16]
     * "本周三到周五有哪些空场"           → [2026-09-16, 2026-09-18]
     *
     * @return array<int, Carbon>
     */
    public static function datesInText(string $text, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today(BookingWindow::TIMEZONE))->copy()->startOfDay();

        // 同一位置从上到下依次尝试所有分支：
        // - "大后天" 必须在列表里，否则最左匹配会先命中 "后天"
        // - "下下周X" 不能拆出单独的 "下下周" 分支，否则 "下下周三" 会被消耗掉星期字
        $pattern = '/大后天|后天|今天|今日|明天|明日|'
            .'(?:下下周|下周|本周|这周|周|星期)([一二三四五六日天1-7])/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $dates = [];

        foreach ($matches as $m) {
            $word = (string) $m[0];

            // "后天/大后天" 会被各自的分支命中："大后天" 里包含 "后天"，
            // 上面的正则把 "大后天" 放在前面优先匹配，避免误判成 +2 天
            if (array_key_exists($word, self::DAY_OFFSETS)) {
                $dates[] = $today->copy()->addDays(self::DAY_OFFSETS[$word]);

                continue;
            }

            $index = self::WEEKDAY_INDEX[$m[1] ?? ''] ?? null;

            if ($index === null) {
                continue;
            }

            $weeks = match (true) {
                str_contains($word, '下下周') => 2,
                str_contains($word, '下周') => 1,
                default => 0,   // 本周 / 这周 / 周X / 星期X
            };

            $dates[] = $today->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($weeks)->addDays($index - 1);
        }

        return $dates;
    }

    /**
     * 查询日期范围：首个日期词为起点、末个为终点；没有日期词时返回 null（交给模型结果）
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function fromText(string $text, ?Carbon $today = null): ?array
    {
        $dates = self::datesInText($text, $today);

        if ($dates === []) {
            return null;
        }

        $from = $dates[0]->copy()->startOfDay();
        $to = end($dates)->copy()->startOfDay();

        return [$from, $to->lt($from) ? $from->copy() : $to];
    }
}
