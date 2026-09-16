<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 教练档案关联登录账号
 *
 * 此前 coaches 是从三张业务表的 coach_name 字符串抽出来的纯名单，与 users 没有任何联系，
 * 「孟宇」是谁的账号只能靠人工记忆。本迁移给每位教练补上对应的账号 id：
 * - user_id 为空：该教练尚未绑定账号（存量教练的初始状态，不影响约课/名单等既有逻辑）
 * - user_id 唯一：一个登录账号只能是一位教练，换绑必须先解绑
 *
 * 与 messages.user_id、booking_records.fixed_schedule_id 保持一致：只加索引、不加外键，
 * 避免 Excel 导入 / 迁移 / 队列场景因为账号缺失导致整批写入失败。
 *
 * 姓名不做双向同步：users.name 是登录昵称，coaches.name 是教案与约课上用的称呼，各自维护。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->after('organization_code')
                ->comment('关联的登录账号 users.id；为空表示尚未绑定账号');

            // 一对一语义：同一账号不能挂在两位教练名下（MySQL/SQLite 均允许多个 NULL）
            $table->unique('user_id', 'coaches_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            // SQLite 下必须先删索引再删列，否则列上残留的索引会让 dropColumn 失败
            $table->dropUnique('coaches_user_id_unique');
            $table->dropColumn('user_id');
        });
    }
};
