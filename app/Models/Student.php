<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

/**
 * 学员档案（主数据，与 booking_records 的 student_name 关联统计）
 *
 * 已上课次数 / 剩余课时不落库，由 booking_records 按学员名聚合：
 * 已上课次 = 非已取消约课记录数；剩余课时 = lessons_total - 已上课次
 */
class Student extends Model
{
    protected $fillable = [
        'name', 'phone', 'coach_name', 'lessons_total', 'remark', 'organization_code',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构
        // 只在未指定时补：总管理员在后台是按「当前管理机构」显式传 organization_code 的，
        // 无条件覆盖会把跨机构新建的学员错写到管理员自己的机构下。
        static::creating(function (Model $model) {
            $code = auth('web')->user()?->organization_code;

            if ($code && blank($model->organization_code)) {
                $model->organization_code = $code;
            }
        });
    }

    /**
     * 该学员在约课表中的上课次数（口径与聊天一致：非已取消记录都算）
     */
    public function getLessonCountAttribute(): int
    {
        return BookingRecord::where('student_name', 'like', '%'.$this->name.'%')
            ->where('status', '!=', BookingRecord::STATUS_CANCELLED)
            ->count();
    }

    /**
     * 剩余课时（不足时按 0 计）
     */
    public function getLessonsRemainingAttribute(): int
    {
        return max(0, $this->lessons_total - $this->lesson_count);
    }
}
