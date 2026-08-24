<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('role')->default('user')->comment('user / assistant');
            $table->string('type')->default('text')->comment('text / image / excel / booking');
            $table->text('content')->comment('文本内容');
            $table->string('image_path')->nullable()->comment('图片消息本地路径');
            $table->string('extra')->nullable()->comment('扩展信息(JSON, 如约课结果/Excel链接)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
