<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 预约记录关联固定课表模板：
 * - 便于按模板重算未来记录（"以后都改"）
 * - 便于区分"固定场"与"临时约课"（只有「不拼」的固定场才独占整场）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_records', function (Blueprint $table) {
            $table->unsignedBigInteger('fixed_schedule_id')->nullable()->after('venue')
                ->comment('来源固定课表模板 id，临时约课为空');
            $table->index(['fixed_schedule_id', 'start_at'], 'idx_fixed_schedule_start');
        });
    }

    public function down(): void
    {
        Schema::table('booking_records', function (Blueprint $table) {
            $table->dropIndex('idx_fixed_schedule_start');
            $table->dropColumn('fixed_schedule_id');
        });
    }
};
