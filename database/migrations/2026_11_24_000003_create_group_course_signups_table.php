<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 拼课报名记录：一个客户对同一活动只能有一条有效报名。
     */
    public function up(): void
    {
        Schema::create('group_course_signups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_course_id');
            $table->string('organization_code', 64)->index()->comment('冗余机构 code，便于后台检索');
            $table->unsignedBigInteger('customer_id');
            $table->string('name')->comment('联系人姓名');
            $table->string('phone')->comment('报名联系电话');
            $table->unsignedInteger('people_count')->default(1)->comment('拼课人数');
            $table->text('remark')->nullable();
            $table->string('status', 20)->default('joined')->comment('joined/cancelled');
            $table->string('source', 20)->default('miniapp')->comment('miniapp/h5');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['group_course_id', 'customer_id']);
            $table->index(['organization_code', 'status']);
            $table->index(['customer_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_course_signups');
    }
};
