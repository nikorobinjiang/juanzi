<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

class GeneratedImage extends Model
{
    protected $fillable = [
        'style_key', 'user_image', 'output_image', 'organization_code',
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
