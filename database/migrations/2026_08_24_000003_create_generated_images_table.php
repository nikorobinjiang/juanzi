<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_images', function (Blueprint $table) {
            $table->id();
            $table->string('style_key', 20)->comment('使用的风格模板 a/b');
            $table->string('user_image', 255)->comment('用户上传的原图路径');
            $table->string('output_image', 255)->comment('生成结果图路径');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_images');
    }
};
