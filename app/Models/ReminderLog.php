<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

/**
 * 上课提醒发送日志（幂等表）
 *
 * 每条记录是一次「推送尝试」的留痕：成功、失败、跳过各一行，用于去重与运营追溯。
 */
class ReminderLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** 汇总模式的范围键 */
    public const SCOPE_SUMMARY = 'summary';

    protected $fillable = [
        'organization_code', 'binding_id', 'openid', 'target_type', 'target_name',
        'remind_date', 'scope_key', 'booking_ids', 'payload',
        'status', 'errcode', 'error', 'sent_at',
    ];

    protected $casts = [
        'binding_id' => 'integer',
        // 刻意不做 date cast：存成纯 'Y-m-d' 字符串，幂等查询才能直接拿 toDateString() 精确比对
        'booking_ids' => 'array',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }
}
