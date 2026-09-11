<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'role', 'type', 'content', 'image_path', 'extra', 'organization_code', 'user_id',
    ];

    protected $casts = [
        'extra' => 'array',
    ];

    protected static function booted(): void
    {
        // 查询自动按当前机构隔离
        static::addGlobalScope(new OrganizationScope);

        // 新建时自动填充机构与归属用户
        // - organization_code：机构级隔离
        // - user_id：聊天记录按登录用户隔离（队列 Job 内已 loginUsingId，同样生效）
        // 仅在为空时填充，调用方显式传值时不覆盖
        static::creating(function (Model $model) {
            $user = auth('web')->user();

            if ($user) {
                if (! $model->organization_code) {
                    $model->organization_code = $user->organization_code;
                }

                if (! $model->user_id) {
                    $model->user_id = $user->getKey();
                }
            }
        });
    }
}
