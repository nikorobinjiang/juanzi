<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_records', function (Blueprint $table) {
            $table->id();
            $table->string('student_name')->comment('学员姓名');
            $table->string('coach_name')->comment('教练姓名');
            $table->dateTime('start_at')->comment('上课开始时间');
            $table->dateTime('end_at')->comment('上课结束时间');
            $table->string('venue', 10)->comment('场地 1A/1B/2A/2B');
            $table->string('status', 20)->default('booked')->comment('booked 已约 / completed 已完成 / cancelled 已取消');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['venue', 'start_at'], 'idx_venue_start');
            $table->index(['start_at'], 'idx_start');
            $table->index(['student_name', 'coach_name'], 'idx_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_records');
    }
};
