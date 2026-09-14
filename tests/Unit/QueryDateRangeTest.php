<?php

namespace Tests\Unit;

use App\Support\QueryDateRange;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 自然语言日期词解析（查询日期范围的单一入口）
 *
 * 模型推算星期会出错（实测"本周三"被算成次日 9月17日 周四），
 * 凡用户文本里出现日期词，都以这里的结果为准。
 */
class QueryDateRangeTest extends TestCase
{
    /** 2026-09-14 是周一 */
    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = Carbon::parse('2026-09-14 10:00', 'Asia/Shanghai');
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function assertTextDates(string $text, array $expected): void
    {
        $dates = QueryDateRange::datesInText($text, $this->monday);

        $this->assertSame(
            $expected,
            array_map(fn (Carbon $day) => $day->format('Y-m-d'), $dates)
        );
    }

    /** 本周三 → 9月16日（模型会算成 9月17日） */
    public function test_weekday_of_current_week(): void
    {
        $this->assertTextDates('查询本周三的空闲场地', ['2026-09-16']);
        $this->assertTextDates('这周三有哪些空场', ['2026-09-16']);
        $this->assertTextDates('周三有空吗', ['2026-09-16']);
        $this->assertTextDates('星期三场地情况', ['2026-09-16']);
    }

    /** 下周X / 下下周X：按周一起算 */
    public function test_weekday_of_next_weeks(): void
    {
        $this->assertTextDates('下周三呢', ['2026-09-23']);
        $this->assertTextDates('下下周三呢', ['2026-09-30']);
    }

    /** 周日的两种写法 */
    public function test_sunday(): void
    {
        $this->assertTextDates('周日有空吗', ['2026-09-20']);
        $this->assertTextDates('星期天有空吗', ['2026-09-20']);
    }

    /** 相对今天的说法 */
    public function test_relative_days(): void
    {
        $this->assertTextDates('今天', ['2026-09-14']);
        $this->assertTextDates('明日', ['2026-09-15']);
        $this->assertTextDates('后天', ['2026-09-16']);
        $this->assertTextDates('大后天', ['2026-09-17']);
    }

    /** 一段范围：首个日期词为起点、末个为终点 */
    public function test_multiple_dates_become_a_range(): void
    {
        $range = QueryDateRange::fromText('本周三到周五有哪些空场', $this->monday);

        $this->assertNotNull($range);
        $this->assertSame('2026-09-16', $range[0]->format('Y-m-d'));
        $this->assertSame('2026-09-18', $range[1]->format('Y-m-d'));
    }

    /** 没提到日期时不接管，交给模型结果 */
    public function test_no_date_word_returns_null(): void
    {
        $this->assertNull(QueryDateRange::fromText('查询空闲场地', $this->monday));
        $this->assertNull(QueryDateRange::fromText('1A 还能约吗', $this->monday));
        $this->assertNull(QueryDateRange::fromText('9月20日有哪些空场', $this->monday));
    }

    /** 不传 today 时取当前日期： travelTo 后依赖注入以外也能正确（周一为一周开始） */
    public function test_defaults_to_today(): void
    {
        $this->travelTo($this->monday);

        $dates = QueryDateRange::datesInText('本周三');

        $this->assertSame(['2026-09-16'], array_map(fn (Carbon $day) => $day->format('Y-m-d'), $dates));
    }
}
