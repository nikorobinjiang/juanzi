<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /** 普通账号：无后台权限 */
    public const ROLE_USER = 'user';

    /** 机构管理员：后台内只能管理自己所属机构 */
    public const ROLE_ORG_ADMIN = 'org_admin';

    /** 总管理员：后台内可切换并管理所有机构 */
    public const ROLE_ADMIN = 'admin';

    /** @var list<string> */
    public const ROLES = [self::ROLE_USER, self::ROLE_ORG_ADMIN, self::ROLE_ADMIN];

    /** @var array<string, string> */
    public const ROLE_LABELS = [
        self::ROLE_USER => '普通用户',
        self::ROLE_ORG_ADMIN => '机构管理员',
        self::ROLE_ADMIN => '总管理员',
    ];

    protected $fillable = [
        'name',
        'username',
        'organization_code',
        'role',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * 关联的教练档案（coaches.user_id 唯一，故为一对一；普通学员账号这里为空）
     */
    public function coach(): HasOne
    {
        return $this->hasOne(Coach::class, 'user_id');
    }

    /**
     * 角色（脏数据兜底为普通用户，避免未知值被当成管理员）
     *
     * 不叫 role()：User 上已存在 role 字段，同名方法会被 Eloquent 当成关联方法解析。
     */
    public function currentRole(): string
    {
        $role = (string) ($this->attributes['role'] ?? '');

        return in_array($role, self::ROLES, true) ? $role : self::ROLE_USER;
    }

    /** 是否总管理员（可管理所有机构） */
    public function isAdmin(): bool
    {
        return $this->currentRole() === self::ROLE_ADMIN;
    }

    /** 是否机构管理员（仅本机构） */
    public function isOrgAdmin(): bool
    {
        return $this->currentRole() === self::ROLE_ORG_ADMIN;
    }

    /** 是否能进后台（总管理员或机构管理员） */
    public function isManager(): bool
    {
        return $this->isAdmin() || $this->isOrgAdmin();
    }

    /** 角色中文名（列表展示用） */
    public function getRoleLabelAttribute(): string
    {
        return self::ROLE_LABELS[$this->currentRole()] ?? $this->currentRole();
    }
}
