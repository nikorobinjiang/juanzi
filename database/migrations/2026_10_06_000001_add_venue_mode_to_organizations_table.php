<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 机构「场地模式」：决定约课时是否分配 / 校验 / 冲突检测场地
 *
 * - required：按场地排课（网球馆、羽毛球馆、篮球馆等），约课必须有场地，沿用现有的
 *             场地白名单校验、自动分配与整场/半场冲突检测
 * - none    ：不按场地排课（游泳馆、棋院等），约课不填场地、不做场地冲突，
 *             只保留营业时段与「一位教练同一时间只能带一节课」的教练冲突
 *
 * 默认 required：存量机构行为完全不变。
 * 迁移顺带把 swim_a（游泳馆A）、qi_yuan_a（棋院A）订正为 none —— 这两类场馆
 * 本来就没有严格场地概念，之后可在后台「机构」区块随时改回。
 */
return new class extends Migration
{
    /** 一次性订正为「不按场地」的机构 */
    private const NONE_VENUE_ORGS = ['swim_a', 'qi_yuan_a'];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('venue_mode', 20)
                ->default('required')
                ->after('auth_code')
                ->comment('场地模式 required按场地排课 / none不按场地排课');
        });

        DB::table('organizations')
            ->whereIn('code', self::NONE_VENUE_ORGS)
            ->update(['venue_mode' => 'none', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('venue_mode');
        });
    }
};
