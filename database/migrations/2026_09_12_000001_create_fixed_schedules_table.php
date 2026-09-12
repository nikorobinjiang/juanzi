<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定课表模板：记录"每周固定"的场次
 *
 * 该表只存"模板"（星期 + 时间 + 场地 + 学员/用途 + 教练），
 * 具体到某一天的预约记录由 FixedScheduleService::materialize() 展开写入 booking_records。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->comment('所属机构');
            $table->string('region', 30)->default('开发区场地')->comment('区域：开发区场地/龙安湖/余之城/其它场地/教育学院');
            $table->string('venue', 20)->comment('场地：1/2（整场 AB）、龙安湖、余之城、一小、信达、教育学院');
            $table->unsignedTinyInteger('weekday')->comment('星期：1=周一 … 7=周日');
            $table->string('start_time', 5)->comment('开始时间 H:i');
            $table->string('end_time', 5)->comment('结束时间 H:i');
            $table->string('student_name', 50)->comment('学员姓名或固定场用途');
            $table->string('coach_name', 50)->nullable()->comment('教练（表格里只写了姓氏就先存姓氏）');
            $table->boolean('exclusive')->default(false)->comment('不拼：该场次独占整场，1A/1B 都不能再约');
            $table->boolean('is_student')->default(true)->comment('是否学员业务：false 为用途场（裘总用场/周例会/教练内训等），不建学员档案');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->date('effective_from')->nullable()->comment('生效起始日');
            $table->date('effective_to')->nullable()->comment('生效结束日');
            $table->boolean('active')->default(true)->comment('是否启用；停用后不再生成新的预约记录');
            $table->timestamps();

            $table->index(['organization_code', 'weekday'], 'idx_fixed_org_weekday');
            $table->index(['venue', 'weekday'], 'idx_fixed_venue_weekday');
            $table->index(['active'], 'idx_fixed_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_schedules');
    }
};
