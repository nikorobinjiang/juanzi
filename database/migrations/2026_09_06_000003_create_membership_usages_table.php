<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_usages', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 30)->index()->comment('所属机构');
            $table->unsignedBigInteger('card_id')->index()->comment('会员卡ID');
            $table->dateTime('used_at')->comment('使用时间');
            $table->string('note', 255)->nullable()->comment('备注');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_usages');
    }
};
