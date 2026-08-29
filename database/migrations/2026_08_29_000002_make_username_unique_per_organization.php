<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 用户名唯一性从"全局唯一"改为"机构内唯一"：
     * - 删除 users_username_unique 全局唯一索引
     * - 新增 (organization_code, username) 联合唯一索引，允许不同机构使用相同用户名
     */
    public function up(): void
    {
        // 存量检查：若已存在 (organization_code, username) 重复记录，中止迁移防止联合唯一索引创建失败
        $duplicates = DB::table('users')
            ->select('organization_code', 'username')
            ->groupBy('organization_code', 'username')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates->map(fn ($d) => "{$d->organization_code}/{$d->username}")->implode(', ');
            throw new RuntimeException(
                '无法将用户名唯一性改为机构内唯一：存在跨机构/同机构重复用户名（'.$detail.'）。请先清理后再执行本迁移。'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->unique(['organization_code', 'username'], 'users_org_username_unique');
        });
    }

    /**
     * 反向恢复：删除联合唯一索引，恢复全局唯一索引。
     * 注：若此时已存在跨机构同名用户，恢复全局唯一索引会失败，需先清理。
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_org_username_unique');
            $table->unique('username', 'users_username_unique');
        });
    }
};
