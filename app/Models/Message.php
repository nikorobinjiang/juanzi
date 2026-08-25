<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'role', 'type', 'content', 'image_path', 'extra', 'organization_code',
    ];

    protected $casts = [
        'extra' => 'array',
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
}
