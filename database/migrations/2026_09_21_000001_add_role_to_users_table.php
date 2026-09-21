<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 账号角色：支撑后台管理入口的权限分层
 *
 * - user      ：普通账号（存量账号的默认值，行为完全不变）
 * - org_admin ：机构管理员，后台内只能看/改自己所属机构
 * - admin     ：总管理员，后台内可切换并管理所有机构
 *
 * 这里只加列与索引，不建外键、不改登录注册主流程；
 * admin 账号本体由 database/seeders/AdminSeeder.php 幂等创建。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)
                ->default('user')
                ->after('organization_code')
                ->comment('角色 user普通/org_admin机构管理员/admin总管理员');

            $table->index('role', 'idx_users_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SQLite 下必须先删索引再删列，否则残留索引会让 dropColumn 失败
            $table->dropIndex('idx_users_role');
            $table->dropColumn('role');
        });
    }
};
