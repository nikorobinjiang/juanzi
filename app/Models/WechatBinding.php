<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 微信 openid 绑定
 *
 * 一条记录 = 「某个微信用户」在某个机构下认领了「某个学员 / 教练」身份。
 * 一个 openid 可以同时持有多个身份（家长管多个孩子、教练同时也是学员），由 target_key 唯一约束区分。
 *
 * 机构字段刻意不在这里做 creating 钩子自动填充：绑定页是免登录的，
 * 没有 auth('web') 可依赖，漏传 organization_code 必须当场暴露出来而不是静默写空串。
 */
class WechatBinding extends Model
{
    public const TYPE_STUDENT = 'student';
    public const TYPE_COACH = 'coach';

    public const SOURCE_PHONE = 'phone';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'organization_code', 'openid', 'target_type', 'target_key',
        'student_name', 'coach_name', 'source', 'enabled', 'last_failed_at', 'bound_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'bound_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离；CLI 下未登录不追加条件
        static::addGlobalScope(new OrganizationScope);
    }

    /**
     * 身份主键快照：'student:12' / 'coach:3'
     */
    public static function targetKey(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /**
     * 只看某类身份
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('target_type', $type);
    }

    /**
     * 只取仍可接收推送的绑定
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * 当前身份对应的姓名（学员或教练二选一）
     */
    public function getNameAttribute(): string
    {
        return (string) ($this->target_type === self::TYPE_COACH ? $this->coach_name : $this->student_name);
    }
}
