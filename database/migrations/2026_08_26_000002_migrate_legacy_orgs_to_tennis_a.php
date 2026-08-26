<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 旧机构数据归并：原 swim（游泳培训班）/ ball（球类培训班）两个机构下的
     * 全部数据（用户账号、聊天记录、约课记录、生成图片）迁移归入网球馆A（tennis_a）。
     * 旧账号改机构码后仍可用原密码登录（登录只看机构+用户名+密码）。
     */
    public function up(): void
    {
        foreach (['users', 'messages', 'booking_records', 'generated_images'] as $table) {
            DB::table($table)
                ->whereIn('organization_code', ['swim', 'ball'])
                ->update(['organization_code' => 'tennis_a']);
        }
    }

    public function down(): void
    {
        // 不可逆：无法还原旧数据归属，仅作注释说明
    }
};
