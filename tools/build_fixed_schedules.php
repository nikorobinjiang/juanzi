<?php

/**
 * 固定课表 Excel → 结构化数据文件 生成器（一次性/可重跑）
 *
 * 源表：storage/app/imports/fixed-schedules.xlsx 的 Sheet1「场地/星期（工作日）」
 * 输出：database/data/fixed_schedules.php（人工核对后再用 fixed-schedules:import 入库）
 * 日志：storage/app/xlsx-parse-log.txt（逐条对应源单元格，便于核对）
 *
 * 用法：php tools/build_fixed_schedules.php [xlsx路径] [输出php路径]
 *
 * 表格约定（来自源表）：
 * - 每 2 列一天：B/C=周一、D/E=周二 … N/O=周日；奇数列=1（AB）场，偶数列=2（AB）场
 * - A 列是时间段行标（07:00-08:00 … 22:00-22:30），单元格自带时间时以自带时间为准
 * - 竖向合并单元格（如 裘总用场 B8:B11）表示占用合并范围的整个时段
 * - 名称后括号里是教练姓氏，尾部「不拼」表示该时段独占场地
 * - 「用途场」（裘总用场/教练内训/团课/集训/金苑/一小/信达）不建学员档案；阿姨是学员
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/* -----------------------------------------------------------------
 | 配置
 | ----------------------------------------------------------------- */

$input = $argv[1] ?? __DIR__.'/../storage/app/imports/fixed-schedules.xlsx';
$outputFile = $argv[2] ?? __DIR__.'/../database/data/fixed_schedules.php';
$logFile = __DIR__.'/../storage/app/xlsx-parse-log.txt';

const REGION_DEVELOPMENT = '开发区场地';
const REGION_LONGANHU = '世纪公园·龙安湖';
const REGION_YUZHICHENG = '余之城球杨';
const REGION_OTHER = '其它场地';
const REGION_EDUCATION = '教育学院';

const REGION_ORDER = [REGION_DEVELOPMENT, REGION_LONGANHU, REGION_YUZHICHENG, REGION_OTHER, REGION_EDUCATION];

/** 源表表头写法 → 标准区域名（源表里是「世纪公园/龙安湖」） */
const REGION_ALIAS = ['世纪公园/龙安湖' => REGION_LONGANHU];

/** 区域 → 场地（开发区按列区分 1/2，其它区域是单一场地） */
const REGION_VENUE = [
    REGION_LONGANHU => '龙安湖',
    REGION_YUZHICHENG => '余之城',
];

/** 列 → 星期（每 2 列一天） */
const COL_WEEKDAY = ['B' => 1, 'C' => 1, 'D' => 2, 'E' => 2, 'F' => 3, 'G' => 3, 'H' => 4, 'I' => 4, 'J' => 5, 'K' => 5, 'L' => 6, 'M' => 6, 'N' => 7, 'O' => 7];

/** 开发区：列 → 场地（奇数列 1 号场，偶数列 2 号场） */
const COL_COURT = ['B' => '1', 'C' => '2', 'D' => '1', 'E' => '2', 'F' => '1', 'G' => '2', 'H' => '1', 'I' => '2', 'J' => '1', 'K' => '2', 'L' => '1', 'M' => '2', 'N' => '1', 'O' => '2'];

/** 用途场关键词（不建学员档案）；阿姨是学员（来打球），不在其中 */
const USAGE_PATTERN = '/用场|集训|团课|内训|周例会|金苑|教练/u';

/** 表里没写「不拼」但实际独占整场的学员场（阿姨 19:00-22:00 占 1 号场） */
const SELF_EXCLUSIVE = ['阿姨'];

/** 开发区整场 → 半场（拼场时一片场地两人各占一边） */
const FULL_TO_HALVES = ['1' => ['1A', '1B'], '2' => ['2A', '2B']];

/** 人工确认的姓名修正：源表里两人写在一起没加分隔符（用户确认「王晓迪周夏桐」是两位学员） */
const NAME_FIXES = ['王晓迪周夏桐' => '王晓迪 周夏桐'];

$warnings = [];
$log = [];

/* -----------------------------------------------------------------
 | 读取表格
 | ----------------------------------------------------------------- */

if (! is_file($input)) {
    fwrite(STDERR, "找不到源文件：{$input}\n");
    exit(1);
}

$spreadsheet = IOFactory::createReaderForFile($input)->load($input);
$sheet = $spreadsheet->getSheet(0);

/** 读取单元格文本（空返回 ''），全角标点归一化 */
$cellText = static function (string $coord) use ($sheet): string {
    $value = $sheet->getCell($coord)->getValue();

    if ($value instanceof \DateTimeInterface) {
        $value = $value->format('H:i');
    }

    $text = str_replace(["\r\n", "\r", "\n", '：', '．', '。'], [' ', ' ', ' ', ':', '.', '.'], (string) $value);

    return trim(preg_replace('/[ \t\x{3000}]+/u', ' ', (string) $text));
};

// 合并区域：单元格 → 区域范围
$mergeRange = [];
foreach ($sheet->getMergeCells() as $range) {
    [$start, $end] = explode(':', $range);
    [$c1, $r1] = Coordinate::coordinateFromString($start);
    [$c2, $r2] = Coordinate::coordinateFromString($end);

    for ($r = (int) $r1; $r <= (int) $r2; $r++) {
        for ($c = Coordinate::columnIndexFromString($c1); $c <= Coordinate::columnIndexFromString($c2); $c++) {
            $mergeRange[Coordinate::stringFromColumnIndex($c).$r] = [(int) $r1, (int) $r2];
        }
    }
}

/* -----------------------------------------------------------------
 | 工具函数
 | ----------------------------------------------------------------- */

/** '7.30' → '07:30'；'8' → '08:00' */
function normTime(string $time): string
{
    $time = trim(str_replace([':', '.'], ':', $time));

    if ($time === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    if (preg_match('/^(\d{1,2})$/', $time, $m)) {
        return sprintf('%02d:00', (int) $m[1]);
    }

    return $time;
}

/** 解析 '7:00-8:00' / '9-10.30' / '22.00-22.30'，返回 [start, end] 或 null */
function parseRange(string $text): ?array
{
    if (! preg_match('/(\d{1,2}(?:[:.]\d{1,2})?)\s*[-~至到]\s*(\d{1,2}(?:[:.]\d{1,2})?)/u', $text, $m)) {
        return null;
    }

    $start = normTime($m[1]);
    $end = normTime($m[2]);

    // 只有小时、且结束小于开始（如 19-20 → 19:00-20:00 正常；23-1 跨天）时补足
    if (strlen($start) === 5 && strlen($end) === 5 && $end <= $start) {
        $end = normTime((string) ((int) substr($m[2], 0, 2) + 24));
        $end = sprintf('%02d:%s', (int) substr($end, 0, 2) - 24, substr($end, 3));
    }

    return [$start, $end];
}

/* -----------------------------------------------------------------
 | 定位各区域的数据行
 | ----------------------------------------------------------------- */

$highestRow = (int) $sheet->getHighestRow();
$regionRows = [];   // ['region' => [startRow, endRow], ...]

$regionOrder = [];
for ($r = 1; $r <= $highestRow; $r++) {
    $raw = $cellText('A'.$r);
    $name = REGION_ALIAS[$raw] ?? $raw;

    if (in_array($name, REGION_ORDER, true)) {
        $regionOrder[] = ['region' => $name, 'header_row' => $r];
    }
}

foreach ($regionOrder as $i => $item) {
    $nextHeader = $regionOrder[$i + 1]['header_row'] ?? ($highestRow + 1);
    $regionRows[$item['region']] = [$item['header_row'] + 1, $nextHeader - 1];
}

// 开发区的行标时间（A 列）
$rowTime = [];

for ($r = $regionRows[REGION_DEVELOPMENT][0]; $r <= $regionRows[REGION_DEVELOPMENT][1]; $r++) {
    $range = parseRange($cellText('A'.$r));

    if ($range === null) {
        $warnings[] = "开发地区第 {$r} 行缺少时间行标：".$cellText('A'.$r);
        continue;
    }

    $rowTime[$r] = $range;
}

/* -----------------------------------------------------------------
 | 解析
 | ----------------------------------------------------------------- */

/** @var array<int, array<string, mixed>> $entries */
$entries = [];
$seen = [];

$addEntry = function (array $entry, string $source) use (&$entries, &$seen, &$log, &$warnings): void {
    $key = implode('|', [
        $entry['region'], $entry['venue'], $entry['weekday'],
        $entry['start_time'], $entry['end_time'], $entry['student_name'], $entry['coach_name'],
    ]);

    if (isset($seen[$key])) {
        $log[] = sprintf('  [去重] %s 与 %s 重复：%s', $source, $seen[$key], json_encode($entry, JSON_UNESCAPED_UNICODE));

        return;
    }

    $seen[$key] = $source;
    $entries[] = $entry;
    $log[] = sprintf(
        '  %-10s %-6s %s %s-%s  %s%s%s   ← %s',
        $entry['region'],
        $entry['venue'],
        ['', '周一', '周二', '周三', '周四', '周五', '周六', '周日'][$entry['weekday']],
        $entry['start_time'],
        $entry['end_time'],
        $entry['student_name'],
        $entry['coach_name'] !== '' ? '（'.$entry['coach_name'].'）' : '',
        $entry['exclusive'] ? ' 不拼' : '',
        $source
    );
};

/** 把单元格文字拆成若干条：时间? 名字（教练）不拼 */
$parseCellEntries = static function (string $text): array {
    $pattern = '/(\d{1,2}(?:[:.]\d{1,2})?(?:\s*[-~至到]\s*\d{1,2}(?:[:.]\d{1,2})?)?)?\s*[)）]?\s*([^\s（()）]+(?:\s+[^\s（()）]+)*?)\s*[（(]([^）)]*)[）)]\s*(不拼)?/u';

    if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $result = [];
    foreach ($matches as $m) {
        $result[] = [
            'time' => trim((string) $m[1]),
            'name' => trim((string) $m[2]),
            'coach' => trim((string) $m[3]),
            'exclusive' => ($m[4] ?? '') === '不拼',
            'raw' => $m[0],
        ];
    }

    return $result;
};

foreach (REGION_ORDER as $region) {
    if (! isset($regionRows[$region])) {
        continue;
    }

    [$firstRow, $lastRow] = $regionRows[$region];
    $log[] = '';
    $log[] = "## {$region}（第 {$firstRow}~{$lastRow} 行）";

    for ($r = $firstRow; $r <= $lastRow; $r++) {
        foreach (COL_WEEKDAY as $col => $weekday) {
            $coord = $col.$r;
            $text = $cellText($coord);

            if ($text === '') {
                continue;
            }

            // 合并单元格：取锚点所在行区间作为默认时段
            $span = $mergeRange[$coord] ?? null;
            $endRow = $span[1] ?? $r;

            $band = null;
            if ($region === REGION_DEVELOPMENT && isset($rowTime[$r], $rowTime[$endRow])) {
                $band = [$rowTime[$r][0], $rowTime[$endRow][1]];
            } elseif ($region === REGION_DEVELOPMENT) {
                $warnings[] = "{$coord} 缺少默认时段，已跳过：{$text}";
                continue;
            }

            // 场地
            if ($region === REGION_DEVELOPMENT) {
                $venue = COL_COURT[$col];
            } elseif ($region === REGION_OTHER) {
                $venue = null; // 由单元格前缀决定（一小/信达）
            } else {
                $venue = REGION_VENUE[$region] ?? $region;
            }

            // ---- 其它场地：一小/信达：教练+时间（出发备注）
            if ($region === REGION_OTHER) {
                if (! preg_match('/^(?<place>[^:]+):\s*(?<rest>.+)$/u', $text, $m)) {
                    $warnings[] = "{$coord} 无法识别场地前缀，已跳过：{$text}";
                    continue;
                }

                $place = trim($m['place']);
                $rest = trim($m['rest']);
                $range = parseRange($rest);

                if ($range === null) {
                    $warnings[] = "{$coord} 缺少时间，已跳过：{$text}";
                    continue;
                }

                preg_match('/(\d{1,2}(?:[:.]\d{1,2})?)\s*[-~至到]\s*(\d{1,2}(?:[:.]\d{1,2})?)/u', $rest, $tm, PREG_OFFSET_CAPTURE);
                $coach = trim(substr($rest, 0, $tm[0][1]));
                $tail = trim(substr($rest, $tm[0][1] + strlen($tm[0][0])));
                $remark = trim($tail, " \t（）()");

                $addEntry([
                    'region' => $region,
                    'venue' => $place,
                    'weekday' => $weekday,
                    'start_time' => $range[0],
                    'end_time' => $range[1],
                    'student_name' => $place,
                    'coach_name' => $coach,
                    'exclusive' => true,
                    'is_student' => false,
                    'remark' => $remark,
                ], $coord);
                continue;
            }

            // ---- 一般单元格：时间? 名字（教练）不拼，可多条
            $parsed = $parseCellEntries($text);

            if ($parsed === []) {
                // 无括号（裘总用场 / 阿姨 / 教练内训 等）
                $name = trim(str_replace('不拼', '', $text));
                $remark = '';

                if (preg_match('/^(.*?)(\d{1,2}(?:[:.]\d{1,2})?\s*[-~至到]\s*\d{1,2}(?:[:.]\d{1,2})?.*)$/u', $name, $m) && $m[1] !== '') {
                    $name = trim($m[1]);
                    $remark = trim($m[2]);
                }

                $range = $band ?? parseRange($text);

                if ($range === null) {
                    $warnings[] = "{$coord} 无法识别时间，已跳过：{$text}";
                    continue;
                }

                $addEntry([
                    'region' => $region,
                    'venue' => $venue,
                    'weekday' => $weekday,
                    'start_time' => $range[0],
                    'end_time' => $range[1],
                    'student_name' => $name,
                    'coach_name' => '',
                    'exclusive' => true,
                    'is_student' => false,
                    'remark' => $remark,
                ], $coord);
                continue;
            }

            // 「不拼」写在格子里，视为整格语义（同格多人一起独占这片场地）
            $cellExclusive = str_contains($text, '不拼');

            foreach ($parsed as $item) {
                $name = $item['name'];

                // 龙安湖XX-XX名字：去掉地名前缀，时间前置
                if (str_starts_with($name, '龙安湖')) {
                    $name = trim(substr($name, strlen('龙安湖')));

                    if (preg_match('/^(\d{1,2}(?:[:.]\d{1,2})?)\s*[-~至到]\s*(\d{1,2}(?:[:.]\d{1,2})?)(.*)$/u', $name, $m)) {
                        $item['time'] = $m[1].'-'.$m[2];
                        $name = trim($m[3]);
                    }
                }

                $range = $item['time'] !== '' ? parseRange($item['time']) : null;
                $range = $range ?? $band;

                if ($range === null) {
                    $warnings[] = "{$coord} 无法识别时间，已跳过：{$text}";
                    continue;
                }

                $isStudent = ! preg_match(USAGE_PATTERN, $name);

                $addEntry([
                    'region' => $region,
                    'venue' => $venue,
                    'weekday' => $weekday,
                    'start_time' => $range[0],
                    'end_time' => $range[1],
                    'student_name' => $name,
                    'coach_name' => $item['coach'],
                    'exclusive' => $cellExclusive || ! $isStudent || in_array($name, SELF_EXCLUSIVE, true),
                    'is_student' => $isStudent,
                    'remark' => '',
                ], $coord);
            }

            // 残余文本检查（防止漏解析）
            $leftover = $text;
            foreach ($parsed as $item) {
                $leftover = str_replace($item['raw'], ' ', $leftover);
            }
            $leftover = trim(str_replace(['不拼', '）', ')'], ' ', $leftover));

            if ($leftover !== '') {
                $warnings[] = "{$coord} 存在未解析文本：「{$leftover}」（原文：{$text}）";
            }
        }
    }
}

/* -----------------------------------------------------------------
 | 人工修正：姓名
 | ----------------------------------------------------------------- */

foreach ($entries as $i => $e) {
    if (isset(NAME_FIXES[$e['student_name']])) {
        $log[] = sprintf('  [姓名修正] %s → %s', $e['student_name'], NAME_FIXES[$e['student_name']]);
        $entries[$i]['student_name'] = NAME_FIXES[$e['student_name']];
    }
}

/* -----------------------------------------------------------------
 | 拼场拆分：同一天、同一片场内时间有交集的场次 = 拼场
 | 一片场地两人各占一边：先排到的落 A 半场，另一方落 B 半场（1A/1B、2A/2B）
 | ----------------------------------------------------------------- */

$playSplitLog = [];
$groups = [];

foreach ($entries as $i => $e) {
    if (! isset(FULL_TO_HALVES[$e['venue']])) {
        continue;
    }

    $groups[$e['weekday'].'#'.$e['venue']][] = $i;
}

foreach ($groups as $groupKey => $indexes) {
    // 时间重叠关系（同一天同一片场地）
    $adj = array_fill_keys($indexes, []);

    foreach ($indexes as $a) {
        foreach ($indexes as $b) {
            if ($a >= $b) {
                continue;
            }

            if ($entries[$a]['start_time'] < $entries[$b]['end_time']
                && $entries[$b]['start_time'] < $entries[$a]['end_time']) {
                $adj[$a][] = $b;
                $adj[$b][] = $a;
            }
        }
    }

    // 只有"与别人时间重叠"的场次才需要拆半场，其它场次仍独占整场
    $splittable = array_keys(array_filter($adj));

    if ($splittable === []) {
        continue;
    }

    // 2 着色（BFS）：色 0 → A 半场，色 1 → B 半场
    $color = [];

    foreach ($splittable as $seed) {
        if (isset($color[$seed])) {
            continue;
        }

        $color[$seed] = 0;
        $queue = [$seed];

        while ($queue) {
            $cur = array_shift($queue);

            foreach ($adj[$cur] as $next) {
                if (isset($color[$next])) {
                    if ($color[$next] === $color[$cur]) {
                        $warnings[] = "拼场无法两分（{$groupKey} 存在三方及以上同时重叠），请人工确认："
                            .$entries[$cur]['student_name'].' 与 '.$entries[$next]['student_name'];
                    }

                    continue;
                }

                $color[$next] = 1 - $color[$cur];
                $queue[] = $next;
            }
        }
    }

    [$weekday, $full] = explode('#', $groupKey);
    $parts = [];

    foreach ($splittable as $i) {
        $half = FULL_TO_HALVES[$full][$color[$i]];
        $entries[$i]['venue'] = $half;
        $entries[$i]['exclusive'] = false; // 拼场即共用整场，不再独占

        $parts[] = sprintf(
            '%s %s-%s（%s）',
            $entries[$i]['student_name'],
            $entries[$i]['start_time'],
            $entries[$i]['end_time'],
            $half
        );
    }

    $playSplitLog[] = sprintf(
        '  %s %s 号场拼场 → %s',
        ['', '周一', '周二', '周三', '周四', '周五', '周六', '周日'][$weekday],
        $full,
        implode(' ＋ ', $parts)
    );
}

if ($playSplitLog !== []) {
    $log[] = '';
    $log[] = '## 拼场拆分（同场地同时段多人 → 半场）';
    $log = array_merge($log, $playSplitLog);
}

/* -----------------------------------------------------------------
 | 排序输出
 | ----------------------------------------------------------------- */

usort($entries, static function ($a, $b) {
    return [
        array_search($a['region'], REGION_ORDER, true),
        $a['weekday'], $a['start_time'], $a['venue'], $a['student_name'],
    ] <=> [
        array_search($b['region'], REGION_ORDER, true),
        $b['weekday'], $b['start_time'], $b['venue'], $b['student_name'],
    ];
});

$weekdayLabels = [1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日'];
$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = '/**';
$lines[] = ' * 固定课表数据（源表：storage/app/imports/fixed-schedules.xlsx → Sheet1）';
$lines[] = ' *';
$lines[] = ' * 由 tools/build_fixed_schedules.php 自动生成，核对后运行：';
$lines[] = ' *   php artisan fixed-schedules:import --dry-run';
$lines[] = ' *   php artisan fixed-schedules:import --org=机构code';
$lines[] = ' *';
$lines[] = ' * 字段说明：';
$lines[] = ' * - venue：开发区单占时用整场「1」「2」（占 1A+1B）；拼场用半场「1A/1B」「2A/2B」；其它区域为 龙安湖/余之城/一小/信达';
$lines[] = ' * - 拼场：同一天同一片场地时间有交集的场次（含集训/团课与其学员）拆成 A/B 半场，各占一边';
$lines[] = ' * - exclusive：表内标注「不拼」或用途场（独占场地）；拼场拆分后的条目为 false';
$lines[] = ' * - is_student：false 表示用途场（裘总用场/教练内训/团课/集训/金苑/一小/信达），不建学员档案；阿姨是学员（来打球），建档案';
$lines[] = ' * - 单元格自带时间优先于行标时间；竖向合并单元格按合并范围整段占用';
$lines[] = ' */';
$lines[] = '';
$lines[] = 'return [';

$currentRegion = '';
foreach ($entries as $e) {
    if ($e['region'] !== $currentRegion) {
        $currentRegion = $e['region'];
        $lines[] = '';
        $lines[] = '    // ============================== '.$currentRegion.' ==============================';
    }

    $lines[] = sprintf(
        '    // %s %s-%s %s %s',
        $weekdayLabels[$e['weekday']],
        $e['start_time'],
        $e['end_time'],
        $e['venue'],
        $e['student_name'].($e['coach_name'] !== '' ? '（'.$e['coach_name'].'）' : '')
    );
    $lines[] = '    ['
        ."'region' => ".var_export($e['region'], true).', '
        ."'venue' => ".var_export($e['venue'], true).', '
        ."'weekday' => ".$e['weekday'].', '
        ."'start_time' => ".var_export($e['start_time'], true).', '
        ."'end_time' => ".var_export($e['end_time'], true).', '
        ."'student_name' => ".var_export($e['student_name'], true).', ';
    $lines[] = "     'coach_name' => ".var_export($e['coach_name'], true).', '
        ."'exclusive' => ".($e['exclusive'] ? 'true' : 'false').', '
        ."'is_student' => ".($e['is_student'] ? 'true' : 'false').', '
        ."'remark' => ".var_export($e['remark'], true).'],';
}

$lines[] = '];';
$lines[] = '';

if (! is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0775, true);
}

file_put_contents($outputFile, implode(PHP_EOL, $lines));
file_put_contents($logFile, implode(PHP_EOL, $log).PHP_EOL.implode(PHP_EOL, array_map(fn ($w) => '[警告] '.$w, $warnings)).PHP_EOL);

// 控制台统计
$byRegion = [];
$byWeekday = [];
foreach ($entries as $e) {
    $byRegion[$e['region']] = ($byRegion[$e['region']] ?? 0) + 1;
    $byWeekday[$e['weekday']] = ($byWeekday[$e['weekday']] ?? 0) + 1;
}

echo '条目总数：'.count($entries).PHP_EOL;
foreach ($byRegion as $region => $count) {
    echo '  · '.$region.'：'.$count.PHP_EOL;
}
echo '按星期：';
foreach ($byWeekday as $weekday => $count) {
    echo $weekdayLabels[$weekday].'='.$count.' ';
}
echo PHP_EOL;
echo '学员条目：'.count(array_filter($entries, fn ($e) => $e['is_student'])).'  ｜ 用途场：'.count(array_filter($entries, fn ($e) => ! $e['is_student'])).PHP_EOL;
echo '拼场拆分：'.count($playSplitLog).' 组'.PHP_EOL;
foreach ($playSplitLog as $line) {
    echo $line.PHP_EOL;
}
echo '警告：'.count($warnings).PHP_EOL;
foreach ($warnings as $w) {
    echo '  ! '.$w.PHP_EOL;
}
echo '输出：'.$outputFile.PHP_EOL;
echo '日志：'.$logFile.PHP_EOL;
