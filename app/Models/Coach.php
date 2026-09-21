<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 教练主数据
 *
 * 此前教练只是散落在 booking_records / students / fixed_schedules 里的 coach_name 字符串，
 * 无法承载别名、联系方式、在职状态。本表成为教练的权威来源：
 * - 名单来源：CrmService::crmContextJson() 给 AI 的上下文
 * - 别名归一：CoachService::resolveCoachName()，写入业务表前统一成规范名
 * - 改名同步：CoachService::syncRename()，主数据与三张业务表一起刷
 */
class Coach extends Model
{
    protected $fillable = [
        'name', 'phone', 'aliases', 'active', 'remark', 'organization_code', 'user_id',
    ];

    protected $casts = [
        'aliases' => 'array',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构
        // 这里刻意不自动绑定 user_id：教练档案多数来自 Excel 导入、固定场导入、
        // AI 约课时的自动建档，录入者往往是前台/管理员，自动绑会把错误的账号写死。
        // 绑定必须由维护命令显式完成：php artisan coaches:link
        static::creating(function (Model $model) {
            $code = auth('web')->user()?->organization_code;

            // 只在未指定时补：后台按「当前管理机构」显式传值时不能被覆盖
            if ($code && blank($model->organization_code)) {
                $model->organization_code = $code;
            }
        });
    }

    /**
     * 关联的登录账号（为空表示尚未绑定）
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 别名数组（空值时返回空数组，方便调用方直接 in_array）
     *
     * @return array<int, string>
     */
    public function getAliasesListAttribute(): array
    {
        $aliases = (array) ($this->aliases ?? []);

        return array_values(array_filter(array_map(
            fn ($alias) => trim((string) $alias),
            $aliases
        ), fn ($alias) => $alias !== ''));
    }
}
