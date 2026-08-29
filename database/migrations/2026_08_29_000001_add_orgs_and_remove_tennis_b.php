<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 新增机构清单（code => name，auth_code 初始为 null，首个注册用户负责初始化） */
    private const NEW_ORGS = [
        'alan_tennis' => '杭州阿蓝网球',
        'ranle_fitness' => '杭州皇冠游泳健身',
    ];

    /** 与 tennis_b 关联的业务表（任一表有数据则禁止删除机构，防止数据孤立） */
    private const TENNIS_B_RELATED_TABLES = [
        'users',
        'messages',
        'booking_records',
        'generated_images',
    ];

    /**
     * 新增杭州阿蓝网球 / 杭州皇冠游泳健身，棋院A改名为杭州奕林棋院（code 不变），
     * 并安全删除网球馆B（先检查关联数据，有数据则中止迁移）。
     */
    public function up(): void
    {
        DB::transaction(function () {
            // 幂等插入新机构，避免中途失败重跑时报唯一键冲突
            $now = now();
            foreach (self::NEW_ORGS as $code => $name) {
                DB::table('organizations')->insertOrIgnore([
                    'code' => $code,
                    'name' => $name,
                    'auth_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 棋院A改名为杭州奕林棋院（内部 code 保持 qi_yuan_a 不变，业务数据自然归属）
            DB::table('organizations')->where('code', 'qi_yuan_a')
                ->update(['name' => '杭州奕林棋院', 'updated_at' => $now]);

            // 删除 tennis_b 前的安全检查：任一张业务表有数据则中止迁移
            $hasData = false;
            $details = [];
            foreach (self::TENNIS_B_RELATED_TABLES as $table) {
                $count = DB::table($table)->where('organization_code', 'tennis_b')->count();
                $details[] = "$table=$count";
                if ($count > 0) {
                    $hasData = true;
                }
            }

            if ($hasData) {
                throw new RuntimeException(
                    '无法删除机构 tennis_b（网球馆B）：存在关联业务数据（'.implode(', ', $details).
                    '）。请先迁移或清理相关数据后再执行本迁移。'
                );
            }

            DB::table('organizations')->where('code', 'tennis_b')->delete();
        });
    }

    /**
     * 反向恢复：删除两个新机构，恢复网球馆B，棋院A显示名恢复为棋院A。
     * 注：tennis_b 原认证码无法从迁移中恢复，如需恢复请手动补 auth_code。
     */
    public function down(): void
    {
        DB::transaction(function () {
            $now = now();
            DB::table('organizations')->insertOrIgnore([
                'code' => 'tennis_b',
                'name' => '网球馆B',
                'auth_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('organizations')->where('code', 'qi_yuan_a')
                ->update(['name' => '棋院A', 'updated_at' => $now]);

            DB::table('organizations')->whereIn('code', array_keys(self::NEW_ORGS))->delete();
        });
    }
};
