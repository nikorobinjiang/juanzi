<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 队列 Job 需要按用户恢复机构上下文；历史数据该列为 null，不影响查询
            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->after('id')
                ->index()
                ->comment('发送者用户 id（队列异步处理时恢复登录态用）');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
