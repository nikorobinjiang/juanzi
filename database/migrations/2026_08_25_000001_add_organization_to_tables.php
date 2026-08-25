<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 多租户账号体系：
     * - users 增加 username（登录名）与 organization_code（所属机构）
     * - 三张业务表增加 organization_code，实现机构级数据隔离
     * - 存量业务数据清空重来（用户已确认，从新账号开始）
     */
    public function up(): void
    {
        // 存量数据清空重来：聊天/约课/图片全部清除，保留表结构
        DB::table('messages')->delete();
        DB::table('booking_records')->delete();
        DB::table('generated_images')->delete();

        Schema::table('users', function (Blueprint $table) {
            // 登录用户名（注册不再要求邮箱）
            $table->string('username', 50)->unique()->after('name');
            // 所属机构 code（关联 config/organizations.php）
            $table->string('organization_code', 30)->index()->after('username');
            // email 不再必填
            $table->string('email')->nullable()->change();
        });

        foreach (['messages', 'booking_records', 'generated_images'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('organization_code', 30)->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (['generated_images', 'booking_records', 'messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('organization_code');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['organization_code', 'username']);
            $table->string('email')->change();
        });
    }
};
