<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->index()->comment('所属机构');
            $table->string('name', 50)->comment('学员姓名');
            $table->string('phone', 20)->nullable()->comment('手机号');
            $table->string('coach_name', 50)->nullable()->comment('当前教练');
            $table->unsignedInteger('lessons_total')->default(0)->comment('累计购买总课时');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['name'], 'idx_students_name');
            $table->index(['phone'], 'idx_students_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
