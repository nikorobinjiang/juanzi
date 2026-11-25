<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 拼课活动主表。显式保存 organization_code，不依赖 web session 的全局机构隔离。
     */
    public function up(): void
    {
        Schema::create('group_courses', function (Blueprint $table) {
            $table->id();
            $table->string('organization_code', 64)->index()->comment('所属机构 code');
            $table->unsignedBigInteger('creator_user_id')->nullable()->comment('创建人 users.id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable()->comment('封面图 storage 路径或 URL');
            $table->string('venue')->nullable()->comment('校区名称');
            $table->string('contact_phone')->nullable()->comment('联系电话');
            $table->string('address')->nullable()->comment('活动地址');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('signup_deadline')->nullable();
            $table->unsignedInteger('capacity')->default(0)->comment('报名人数上限');
            $table->unsignedInteger('signed_up_count')->default(0)->comment('已报名人数（冗余计数）');
            $table->string('status', 20)->default('draft')->comment('draft/published/ended/cancelled');
            $table->string('share_token')->unique()->comment('分享令牌，公开详情与二维码均使用');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['organization_code', 'status']);
            $table->index(['organization_code', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_courses');
    }
};
