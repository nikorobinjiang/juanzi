<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'phone',
        'miniapp_openid',
        'unionid',
        'avatar',
        'last_login_at',
    ];

    protected $hidden = [
        'miniapp_openid',
        'unionid',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(GroupCourseSignup::class);
    }

    /** 日志与接口只返回掩码手机号，不暴露完整号码 */
    public function maskedPhone(): string
    {
        $phone = (string) $this->phone;

        return strlen($phone) >= 7
            ? substr($phone, 0, 3).'****'.substr($phone, -4)
            : $phone;
    }
}
