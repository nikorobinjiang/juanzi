<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_cards', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->index()->comment('所属机构');
            $table->string('member_name', 50)->comment('会员姓名');
            $table->string('phone', 20)->nullable()->comment('手机号');
            $table->string('card_type', 10)->comment('购卡类型 month月卡/year年卡/visits次卡');
            $table->unsignedInteger('total_count')->nullable()->comment('次卡总次数（月/年卡为空）');
            $table->date('start_at')->nullable()->comment('起卡日期');
            $table->date('end_at')->nullable()->comment('到期日期');
            $table->unsignedInteger('used_count')->default(0)->comment('已使用次数');
            $table->string('note', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['member_name'], 'idx_cards_member_name');
            $table->index(['phone'], 'idx_cards_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_cards');
    }
};
