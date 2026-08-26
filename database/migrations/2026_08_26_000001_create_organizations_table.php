<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 机构表（替代 config/organizations.php）：
     * - code：机构唯一标识，业务数据的 organization_code 关联此列
     * - name：机构显示名称（登录/注册下拉、页面顶栏）
     * - auth_code：6 位字母数字认证码；null 表示该机构尚未初始化（首次注册时生成/自填）
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 50);
            $table->string('auth_code', 6)->nullable()->index();
            $table->timestamps();
        });

        // 种子数据：4 个机构，认证码初始为 null（首个注册该机构的用户负责初始化）
        DB::table('organizations')->insert([
            ['code' => 'tennis_a', 'name' => '网球馆A', 'auth_code' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'tennis_b', 'name' => '网球馆B', 'auth_code' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'swim_a', 'name' => '游泳馆A', 'auth_code' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'qi_yuan_a', 'name' => '棋院A', 'auth_code' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
