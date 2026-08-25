<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 机构级数据隔离全局作用域
 *
 * 已登录时自动按当前用户的机构 code 过滤查询；
 * 未登录（CLI / artisan / 未认证请求）不追加过滤条件，
 * 保证迁移、队列、tinker 等非请求上下文不受影响。
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $code = auth('web')->user()?->organization_code;

        if ($code) {
            $builder->where($model->getTable().'.organization_code', $code);
        }
    }
}
