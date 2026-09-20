<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信 openid 绑定表（学员 / 教练通用）
 *
 * booking_records 与 students / coaches 之间没有外键，全靠 student_name / coach_name 字符串关联，
 * 因此这里必须冗余存姓名，才能在推送时从约课记录反查到 openid。
 *
 * 学员与教练共用一张表：两者的匹配逻辑、绑定入口、幂等日志、解绑动作完全一致，
 * 差别只在姓名字段，拆成两张表会把这些逻辑全部写两遍。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->comment('所属机构');
            $table->string('openid', 64)->comment('用户在服务号下的唯一标识');
            $table->string('target_type', 10)->comment('身份：student 学员 / coach 教练');
            $table->string('target_key', 40)->comment('身份主键快照：student:12 / coach:3，唯一约束用');
            $table->string('student_name', 50)->nullable()->comment('学员姓名快照（冗余，与 booking_records.student_name 对齐）');
            $table->string('coach_name', 50)->nullable()->comment('教练姓名快照（冗余，与 booking_records.coach_name 对齐）');
            $table->string('source', 20)->default('phone')->comment('绑定来源：phone 手机号 / manual 手动选择');
            $table->boolean('enabled')->default(true)->comment('是否仍可接收推送；收到 43004/40003 后置 false');
            $table->timestamp('last_failed_at')->nullable()->comment('最近一次发送失败时间');
            $table->timestamp('bound_at')->nullable()->comment('绑定 / 最近一次重绑时间');
            $table->timestamps();

            // 同一个微信重复绑定同一身份时走 upsert，不产生重复行
            $table->unique(['organization_code', 'openid', 'target_key'], 'wechat_bindings_target_unique');
            $table->index(['organization_code', 'target_type', 'openid'], 'idx_bindings_org_type');
            $table->index(['organization_code', 'student_name'], 'idx_bindings_student_name');
            $table->index(['organization_code', 'coach_name'], 'idx_bindings_coach_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_bindings');
    }
};
