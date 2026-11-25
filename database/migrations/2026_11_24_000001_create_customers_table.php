<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 小程序客户档案：手机号为唯一键，openid 为快捷登录凭证。
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->comment('微信昵称或用户填写的联系人姓名');
            $table->string('phone')->unique()->comment('手机号，客户唯一键');
            $table->string('miniapp_openid')->nullable()->unique()->comment('小程序 openid');
            $table->string('unionid')->nullable()->index()->comment('微信开放平台 unionid');
            $table->string('avatar')->nullable()->comment('头像 URL');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
