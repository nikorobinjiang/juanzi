<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * 总管理员账号（幂等）
 *
 * 后台管理需要至少一个总管理员：admin 归属某个机构登录（复用现有「选机构+用户名+密码」流程），
 * 登录后在后台可切换到任意机构管理。总管理员不建议删除，本 seeder 也只做创建/补齐角色，
 * 重复执行不会重置已有 admin 的密码（避免把线上已改过的密码刷回初始密码）。
 *
 * 用法：
 *   php artisan db:seed --class=AdminSeeder
 *   ADMIN_INITIAL_PASSWORD=xxx php artisan db:seed --class=AdminSeeder   # 指定初始密码
 *
 * 忘记密码时不用重跑 seeder：用其他管理员在后台重置，或让总管理员自己改密码。
 */
class AdminSeeder extends Seeder
{
    /** 总管理员登录名 */
    private const USERNAME = 'admin';

    /** 默认初始密码（可通过环境变量 ADMIN_INITIAL_PASSWORD 覆盖） */
    private const DEFAULT_PASSWORD = 'admin123';

    /**
     * 总管理员归属机构：优先取 organizations 表第一条，保证在任何机构清单下都能落地
     */
    private function organizationCode(): string
    {
        return (string) (Organization::query()->orderBy('id')->value('code') ?? '');
    }

    public function run(): void
    {
        $code = $this->organizationCode();

        if ($code === '') {
            $this->command?->warn('organizations 表为空，跳过 admin 账号创建');

            return;
        }

        $password = (string) (env('ADMIN_INITIAL_PASSWORD') ?: self::DEFAULT_PASSWORD);

        /** @var User|null $admin */
        $admin = User::query()
            ->where('username', self::USERNAME)
            ->where('organization_code', $code)
            ->first();

        if (! $admin) {
            $admin = User::create([
                'name' => '总管理员',
                'username' => self::USERNAME,
                'password' => $password,
                'organization_code' => $code,
                'role' => User::ROLE_ADMIN,
            ]);

            $this->command?->info("已创建总管理员 admin（机构 {$code}）");

            Log::info('创建总管理员账号', [
                'user_id' => $admin->getKey(),
                'organization_code' => $code,
            ]);

            return;
        }

        // 已存在：只补齐角色，不覆盖密码（避免把已改过的密码刷回初始值）
        $dirty = false;

        if ($admin->currentRole() !== User::ROLE_ADMIN) {
            $admin->role = User::ROLE_ADMIN;
            $dirty = true;
        }

        if ($dirty) {
            $admin->save();
            $this->command?->info("已更新总管理员 admin（机构 {$code}）");
        } else {
            $this->command?->info("总管理员 admin 已存在（机构 {$code}），未做改动");
        }
    }
}
