<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 会员卡使用记录（聊天报备"某某会员用了一次"时写入，留痕便于对账）
 */
class MembershipUsage extends Model
{
    protected $fillable = [
        'card_id', 'used_at', 'note', 'organization_code',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构
        static::creating(function (Model $model) {
            $code = auth('web')->user()?->organization_code;

            if ($code) {
                $model->organization_code = $code;
            }
        });
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(MembershipCard::class, 'card_id');
    }
}
