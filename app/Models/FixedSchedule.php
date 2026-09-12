<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 固定课表模板：记录"每周固定"的场次
 *
 * 模板只描述"每周几、几点、哪个场地、谁（学员或用途）、哪位教练"，
 * 具体到某一天的预约记录由 FixedScheduleService::materialize() 展开写入 booking_records。
 */
class FixedSchedule extends Model
{
    /** 周二=2 … 周日=7 的中文标签（周一为 1） */
    public const WEEKDAY_LABELS = [
        1 => '周一',
        2 => '周二',
        3 => '周三',
        4 => '周四',
        5 => '周五',
        6 => '周六',
        7 => '周日',
    ];

    /** 区域常量 */
    public const REGION_DEVELOPMENT = '开发区场地';
    public const REGION_LONGANHU = '世纪公园·龙安湖';
    public const REGION_YUZHICHENG = '余之城球杨';
    public const REGION_OTHER = '其它场地';
    public const REGION_EDUCATION = '教育学院';

    protected $fillable = [
        'organization_code', 'region', 'venue', 'weekday',
        'start_time', 'end_time', 'student_name', 'coach_name',
        'exclusive', 'is_student', 'remark',
        'effective_from', 'effective_to', 'active',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'exclusive' => 'boolean',
        'is_student' => 'boolean',
        'active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构；CLI 导入时会显式传入 organization_code
        static::creating(function (Model $model) {
            $code = auth('web')->user()?->organization_code;

            if ($code) {
                $model->organization_code = $code;
            }
        });
    }

    /**
     * 该模板展开出的预约记录
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(BookingRecord::class, 'fixed_schedule_id');
    }

    public function getWeekdayLabelAttribute(): string
    {
        return self::WEEKDAY_LABELS[$this->weekday] ?? '';
    }

    /**
     * 模板的可读描述（用于聊天回复/核对清单）
     */
    public function getSummaryAttribute(): string
    {
        return $this->weekday_label.' '.substr($this->start_time, 0, 5).'-'.substr($this->end_time, 0, 5)
            .' '.$this->venue.' · '.$this->student_name
            .($this->coach_name ? '（教练 '.$this->coach_name.'）' : '');
    }
}
