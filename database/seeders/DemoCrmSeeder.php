<?php

namespace Database\Seeders;

use App\Models\BookingRecord;
use App\Models\MembershipCard;
use App\Models\MembershipUsage;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * CRM 概览演示数据
 *
 * - 学员档案（姓名/手机号/当前教练/累计课时）
 * - 约课记录（completed 历史 + booked 未来，供学员上课次数/教练统计）
 * - 三类会员卡（月卡/年卡/次卡）与报备使用记录
 *
 * 幂等：学员/会员卡已有数据则跳过；演示约课用固定 remark 标记，可安全重建。
 */
class DemoCrmSeeder extends Seeder
{
    private string $orgCode = '';

    /** 演示约课标记（重建时先按该标记清理） */
    private const DEMO_REMARK = '演示数据';

    public function run(): void
    {
        $this->orgCode = (string) (User::query()->value('organization_code')
            ?? Organization::query()->value('code')
            ?? '');

        if ($this->orgCode === '') {
            $this->command?->warn('没有可用机构（users/organizations 为空），跳过演示数据。');

            return;
        }

        $hasCrmData = Student::where('organization_code', $this->orgCode)->exists()
            || MembershipCard::where('organization_code', $this->orgCode)->exists();

        if ($hasCrmData) {
            $this->command?->info('当前机构已有 CRM 数据，跳过演示种子。');

            return;
        }

        $this->seedStudents();
        $this->seedBookings();
        $this->seedMemberships();

        $this->command?->info('CRM 演示数据已生成：学员/约课/会员卡/使用记录。');
    }

    /* -----------------------------------------------------------------
     | 学员档案
     | ----------------------------------------------------------------- */

    private function seedStudents(): void
    {
        $students = [
            ['name' => '王小虎', 'phone' => '13800000001', 'coach' => '张伟', 'total' => 30, 'remark' => '每周一三五上午'],
            ['name' => '李小萌', 'phone' => '13800000002', 'coach' => '张伟', 'total' => 20, 'remark' => ''],
            ['name' => '赵钱钱', 'phone' => '13800000003', 'coach' => '李强', 'total' => 24, 'remark' => '基础提高班'],
            ['name' => '孙悦',   'phone' => '13800000004', 'coach' => '李强', 'total' => 12, 'remark' => ''],
            ['name' => '周舟',   'phone' => '13800000005', 'coach' => '张伟', 'total' => 10, 'remark' => '新学员'],
            ['name' => '陈晓',   'phone' => '13800000006', 'coach' => null,  'total' => 0,  'remark' => '待分配教练'],
        ];

        foreach ($students as $s) {
            Student::create([
                'organization_code' => $this->orgCode,
                'name' => $s['name'],
                'phone' => $s['phone'],
                'coach_name' => $s['coach'],
                'lessons_total' => $s['total'],
                'remark' => $s['remark'],
            ]);
        }
    }

    /* -----------------------------------------------------------------
     | 约课记录（历史 completed + 未来 booked）
     | ----------------------------------------------------------------- */

    private function seedBookings(): void
    {
        // 先清理历史演示约课，保证重复执行不堆积
        BookingRecord::where('organization_code', $this->orgCode)
            ->where('remark', self::DEMO_REMARK)
            ->delete();

        $list = [
            // [学员, 教练, 日期, 时间, 场地, 状态]
            // —— 历史已完成 ——
            ['王小虎', '张伟', '2026-08-25', '10:00', '1A', BookingRecord::STATUS_COMPLETED],
            ['王小虎', '张伟', '2026-08-27', '10:00', '1B', BookingRecord::STATUS_COMPLETED],
            ['王小虎', '张伟', '2026-09-01', '09:00', '2A', BookingRecord::STATUS_COMPLETED],
            ['王小虎', '张伟', '2026-09-03', '15:00', '1B', BookingRecord::STATUS_COMPLETED],
            ['李小萌', '张伟', '2026-08-26', '11:00', '1A', BookingRecord::STATUS_COMPLETED],
            ['李小萌', '张伟', '2026-09-02', '10:00', '2B', BookingRecord::STATUS_COMPLETED],
            ['赵钱钱', '李强', '2026-08-25', '14:00', '1B', BookingRecord::STATUS_COMPLETED],
            ['赵钱钱', '李强', '2026-08-28', '09:00', '1A', BookingRecord::STATUS_COMPLETED],
            ['赵钱钱', '李强', '2026-09-01', '14:00', '1B', BookingRecord::STATUS_COMPLETED],
            ['孙悦',   '李强', '2026-08-29', '16:00', '2A', BookingRecord::STATUS_COMPLETED],
            ['孙悦',   '李强', '2026-09-03', '11:00', '1A', BookingRecord::STATUS_COMPLETED],
            ['周舟',   '张伟', '2026-09-04', '09:00', '1A', BookingRecord::STATUS_COMPLETED],
            // —— 未来已约 ——
            ['王小虎', '张伟', '2026-09-08', '10:00', '1A', BookingRecord::STATUS_BOOKED],
            ['王小虎', '张伟', '2026-09-10', '10:00', '1B', BookingRecord::STATUS_BOOKED],
            ['王小虎', '张伟', '2026-09-15', '10:00', '1A', BookingRecord::STATUS_BOOKED],
            ['李小萌', '张伟', '2026-09-09', '11:00', '1A', BookingRecord::STATUS_BOOKED],
            ['李小萌', '张伟', '2026-09-16', '11:00', '2A', BookingRecord::STATUS_BOOKED],
            ['赵钱钱', '李强', '2026-09-08', '14:00', '1B', BookingRecord::STATUS_BOOKED],
            ['赵钱钱', '李强', '2026-09-12', '09:00', '1A', BookingRecord::STATUS_BOOKED],
            ['孙悦',   '李强', '2026-09-11', '16:00', '2B', BookingRecord::STATUS_BOOKED],
            ['孙悦',   '李强', '2026-09-18', '16:00', '2A', BookingRecord::STATUS_BOOKED],
            ['周舟',   '张伟', '2026-09-13', '09:00', '1B', BookingRecord::STATUS_BOOKED],
        ];

        foreach ($list as [$student, $coach, $date, $time, $venue, $status]) {
            $startAt = Carbon::parse($date.' '.$time, 'Asia/Shanghai');

            BookingRecord::create([
                'organization_code' => $this->orgCode,
                'student_name' => $student,
                'coach_name' => $coach,
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addHour(),
                'venue' => $venue,
                'status' => $status,
                'remark' => self::DEMO_REMARK,
            ]);
        }
    }

    /* -----------------------------------------------------------------
     | 会员卡与使用记录
     | ----------------------------------------------------------------- */

    private function seedMemberships(): void
    {
        // [会员姓名, 手机号, 卡型, 总次数(次卡), 起, 止, 已用, 备注]
        $cards = [
            // 年卡
            ['钱多多', '13900000001', MembershipCard::TYPE_YEAR, null, '2026-03-01', '2027-02-28', 0, ''],
            // 次卡 15（同一会员双卡）
            ['钱多多', '13900000001', MembershipCard::TYPE_VISITS, 15, '2026-08-01', null, 3, '游泳次卡'],
            // 月卡（未到期）
            ['吴芳',   '13900000002', MembershipCard::TYPE_MONTH, null, '2026-08-20', '2026-09-19', 0, ''],
            // 次卡 20
            ['郑晓雨', '13900000003', MembershipCard::TYPE_VISITS, 20, '2026-07-01', null, 5, '游泳次卡'],
            // 年卡（同一会员双卡）
            ['郑晓雨', '13900000003', MembershipCard::TYPE_YEAR, null, '2026-01-01', '2026-12-31', 0, ''],
            // 次卡 30
            ['冯军',   '13900000004', MembershipCard::TYPE_VISITS, 30, '2026-06-01', null, 12, '游泳次卡'],
            // 已过期月卡（演示"已过期"标识）
            ['冯军',   '13900000004', MembershipCard::TYPE_MONTH, null, '2026-08-01', '2026-08-31', 0, '已过期月卡'],
            // 新办年卡
            ['何静',   '13900000005', MembershipCard::TYPE_YEAR, null, '2026-09-01', '2027-08-31', 0, ''],
        ];

        foreach ($cards as [$name, $phone, $type, $total, $start, $end, $used, $note]) {
            $card = MembershipCard::create([
                'organization_code' => $this->orgCode,
                'member_name' => $name,
                'phone' => $phone,
                'card_type' => $type,
                'total_count' => $total,
                'start_at' => $start,
                'end_at' => $end,
                'used_count' => $used,
                'note' => $note,
            ]);

            // 已使用次数补上使用记录（近若干天内递减日期）
            if ($used > 0) {
                $base = Carbon::now('Asia/Shanghai')->subDays($used + 2);

                for ($i = 0; $i < $used; $i++) {
                    MembershipUsage::create([
                        'organization_code' => $this->orgCode,
                        'card_id' => $card->id,
                        'used_at' => $base->copy()->addDays($i),
                        'note' => '演示到店',
                    ]);
                }
            }
        }
    }
}
