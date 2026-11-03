<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上课提醒发送日志（幂等表）
 *
 * 去重语义：同一机构 + 同一天 + 同一微信用户 + 同一身份 + 同一范围，一天只发一次。
 * 汇总模式 scope_key 恒为 'summary'；--per-lesson 模式为 booking_record_id。
 *
 * 用表而不是 Cache 做去重：Cache 会丢、跨天排查困难，而这张表还能支撑发送失败追溯与补发。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->comment('所属机构');
            $table->foreignId('binding_id')->nullable()->comment('关联的绑定记录，解绑后仍保留历史');
            $table->string('openid', 64)->comment('接收者 openid');
            $table->string('target_type', 10)->comment('身份：student / coach');
            $table->string('target_name', 50)->comment('接收者姓名快照');
            $table->date('remind_date')->comment('提醒所指向的上课日期');
            $table->string('scope_key', 40)->default('summary')->comment("范围键：summary 汇总 / booking id 单条");
            $table->json('booking_ids')->nullable()->comment('本次覆盖的约课记录 id');
            $table->json('payload')->nullable()->comment('实际发出的模板数据，便于排查');
            $table->string('status', 10)->default('sent')->comment('sent 已发 / failed 失败 / skipped 跳过');
            $table->string('errcode', 20)->nullable()->comment('微信返回的错误码');
            $table->string('error', 255)->nullable()->comment('错误摘要');
            $table->timestamp('sent_at')->nullable()->comment('发送成功时间');
            $table->timestamps();

            $table->unique(
                ['organization_code', 'remind_date', 'openid', 'target_type', 'scope_key'],
                'reminder_logs_idempotent_unique'
            );
            $table->index(['remind_date', 'target_type'], 'idx_reminder_date_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
