<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会员卡（购卡记录）
 *
 * 卡型：month 月卡 / year 年卡 / visits 次卡
 * - 次卡：total_count 总次数，剩余 = total_count - used_count
 * - 月卡 / 年卡：以 start_at ~ end_at 为准，剩余天数动态计算
 */
class MembershipCard extends Model
{
    public const TYPE_MONTH = 'month';
    public const TYPE_YEAR = 'year';
    public const TYPE_VISITS = 'visits';

    public const TYPE_LABELS = [
        self::TYPE_MONTH => '月卡',
        self::TYPE_YEAR => '年卡',
        self::TYPE_VISITS => '次卡',
    ];

    protected $fillable = [
        'member_name', 'phone', 'card_type', 'total_count',
        'start_at', 'end_at', 'used_count', 'note', 'organization_code',
    ];

    protected $casts = [
        'start_at' => 'date',
        'end_at' => 'date',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构
        // 只在未指定时补：总管理员跨机构开卡时会显式传 organization_code，不能覆盖。
        static::creating(function (Model $model) {
            $code = auth('web')->user()?->organization_code;

            if ($code && blank($model->organization_code)) {
                $model->organization_code = $code;
            }
        });
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MembershipUsage::class, 'card_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->card_type] ?? $this->card_type;
    }

    /**
     * 剩余次数（仅次卡有意义）
     */
    public function getRemainingCountAttribute(): int
    {
        return max(0, (int) $this->total_count - (int) $this->used_count);
    }

    /**
     * 剩余天数：按今天 00:00 起算；已过期为负数
     */
    public function getRemainingDaysAttribute(): int
    {
        if (! $this->end_at) {
            return 0;
        }

        return (int) Carbon::today('Asia/Shanghai')->diffInDays($this->end_at, false);
    }

    public function isExpired(): bool
    {
        return $this->end_at !== null && $this->end_at->lt(Carbon::today('Asia/Shanghai'));
    }
}
