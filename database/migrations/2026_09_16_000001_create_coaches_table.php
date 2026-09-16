<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 教练主数据表
 *
 * 此前教练只是 booking_records / students / fixed_schedules 三张表里的 coach_name 字符串，
 * 同一教练的不同叫法（王教练/小王/王明）无法归一，改名也只能三表批量刷字符串。
 * 本表作为教练的权威来源：名单、别名归一、启用状态都以这里为准。
 *
 * 业务表的 coach_name 本阶段保留不变（历史数据与 Excel 导入都依赖它），
 * 写入前经 CoachService::resolveCoachName() 归一，读取时仍是同一个字符串。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->comment('所属机构');
            $table->string('name', 50)->comment('教练姓名（规范名，业务表 coach_name 与之对齐）');
            $table->string('phone', 20)->nullable()->comment('联系电话');
            $table->json('aliases')->nullable()->comment('别名数组：小王/王教练等写法，用于入库前归一');
            $table->boolean('active')->default(true)->comment('是否在职；停用后不再出现在给 AI 的名单里');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            // 机构内姓名唯一（与 users 的 users_org_username_unique 同一套路）
            $table->unique(['organization_code', 'name'], 'coaches_org_name_unique');
            $table->index(['organization_code', 'active'], 'idx_coaches_org_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaches');
    }
};
