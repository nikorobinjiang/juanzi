<?php

/**
 * 固定课表数据（源表：storage/app/imports/fixed-schedules.xlsx → Sheet1）
 *
 * 由 tools/build_fixed_schedules.php 自动生成，核对后运行：
 *   php artisan fixed-schedules:import --dry-run
 *   php artisan fixed-schedules:import --org=机构code
 *
 * 字段说明：
 * - venue：开发区单占时用整场「1」「2」（占 1A+1B）；拼场用半场「1A/1B」「2A/2B」；其它区域为 龙安湖/余之城/一小/信达
 * - 拼场：同一天同一片场地时间有交集的场次（含集训/团课与其学员）拆成 A/B 半场，各占一边
 * - exclusive：表内标注「不拼」或用途场（独占场地）；拼场拆分后的条目为 false
 * - is_student：false 表示用途场（裘总用场/阿姨/教练内训/团课/集训/金苑/一小/信达），不建学员档案
 * - 单元格自带时间优先于行标时间；竖向合并单元格按合并范围整段占用
 */

return [

    // ============================== 开发区场地 ==============================
    // 周一 10:00-11:00 1 明妈（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 1, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '明妈', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 11:00-12:00 1 沈见高（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 1, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '沈见高', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 11:00-12:00 2 陆雅婧（袁）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '陆雅婧', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 12:00-16:00 1 裘总用场
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 1, 'start_time' => '12:00', 'end_time' => '16:00', 'student_name' => '裘总用场', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周一 12:00-13:00 2 卲总（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '12:00', 'end_time' => '13:00', 'student_name' => '卲总', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 13:00-14:00 2 陈骞（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '陈骞', 
     'coach_name' => '黎', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周一 17:00-18:00 1 棉花（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 1, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '棉花', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 17:30-18:30 2 小腾腾（李）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '17:30', 'end_time' => '18:30', 'student_name' => '小腾腾', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 18:00-19:00 1A 岩儿（余）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '岩儿', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 18:00-19:00 1B 吕江涵（徐）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '吕江涵', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周一 19:00-22:00 1 阿姨
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '22:00', 'student_name' => '阿姨', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周一 19:00-20:00 2 吴忠一家（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '吴忠一家', 
     'coach_name' => '余', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周一 20:00-21:00 2 余哥（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 1, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '余哥', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 08:00-09:00 1 沈琦（孟）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '09:00', 'student_name' => '沈琦', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 10:00-12:00 1 教练内训
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'student_name' => '教练内训', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周二 12:00-16:00 1 裘总用场
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '12:00', 'end_time' => '16:00', 'student_name' => '裘总用场', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => '12-13周例会'],
    // 周二 17:00-18:00 1 庞丽萍，边萍霞（黎）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '庞丽萍，边萍霞', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 17:00-18:00 2 丁琪（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 2, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '丁琪', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周二 18:00-19:00 1 王晓迪 周夏桐（黎）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '王晓迪 周夏桐', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 18:00-19:00 2 唐秋娟（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 2, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '唐秋娟', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周二 19:00-20:00 1A 张乂文（李）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '张乂文', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 19:00-20:00 1B 小鸡仔（余）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '小鸡仔', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 19:00-20:00 2A 黄啧（徐）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '黄啧', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 19:00-20:00 2B 高律（袁）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '高律', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周二 20:00-21:00 1 ALEX（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => 'ALEX', 
     'coach_name' => '余', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周二 20:00-21:00 2 吕先生（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 2, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '吕先生', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周二 21:00-22:00 1 陈陈（徐）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 2, 'start_time' => '21:00', 'end_time' => '22:00', 'student_name' => '陈陈', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周三 09:00-10:00 1 柠檬（李）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '柠檬', 
     'coach_name' => '李', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周三 10:00-11:00 1 明妈（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '明妈', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 10:00-11:00 2 天天（李）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '天天', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 11:00-12:00 1 沈见高（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '沈见高', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 11:00-12:00 2 陆雅婧（袁）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '陆雅婧', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 12:00-16:00 1 裘总用场
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '12:00', 'end_time' => '16:00', 'student_name' => '裘总用场', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周三 12:00-13:00 2 卲总（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '12:00', 'end_time' => '13:00', 'student_name' => '卲总', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 13:00-14:00 2 陈总（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '陈总', 
     'coach_name' => '黎', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周三 15:00-16:00 2 沈琦（孟）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '15:00', 'end_time' => '16:00', 'student_name' => '沈琦', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 16:30-17:30 1 叶颖（李）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '16:30', 'end_time' => '17:30', 'student_name' => '叶颖', 
     'coach_name' => '李', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周三 16:30-17:30 2A 棉花（余）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 3, 'start_time' => '16:30', 'end_time' => '17:30', 'student_name' => '棉花', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 17:00-18:00 2B 杨建芳（余）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 3, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '杨建芳', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 18:00-19:00 1 kk（徐）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => 'kk', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周三 18:00-19:00 2 小灰灰（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '小灰灰', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 19:00-22:00 1 阿姨
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 3, 'start_time' => '19:00', 'end_time' => '22:00', 'student_name' => '阿姨', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周三 19:00-20:00 2 倪文华（孟）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '倪文华', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 20:00-21:00 2 余哥（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '余哥', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周三 21:00-22:00 2 沈总同（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 3, 'start_time' => '21:00', 'end_time' => '22:00', 'student_name' => '沈总同', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 08:00-09:00 1 吴利亚（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '08:00', 'end_time' => '09:00', 'student_name' => '吴利亚', 
     'coach_name' => '王', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 09:00-10:00 1 陈捷（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '陈捷', 
     'coach_name' => '王', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 10:00-11:00 1 王丽（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '王丽', 
     'coach_name' => '王', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 12:00-16:00 1 裘总用场
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '12:00', 'end_time' => '16:00', 'student_name' => '裘总用场', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周四 15:00-16:00 2 沈燕平（李）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 4, 'start_time' => '15:00', 'end_time' => '16:00', 'student_name' => '沈燕平', 
     'coach_name' => '李', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 18:00-19:00 1 邵鑫涛（李）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '邵鑫涛', 
     'coach_name' => '李', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 18:00-19:00 2 梅敏（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 4, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '梅敏', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周四 19:00-20:00 1 Stacy（黎）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => 'Stacy', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 19:00-20:00 2 朱燕（袁）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 4, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '朱燕', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 20:00-21:00 1 何星（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '何星', 
     'coach_name' => '王', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 20:00-21:00 2A 吴晓雯（黎）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 4, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '吴晓雯', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 20:00-21:00 2B 小姚（袁）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 4, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '小姚', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周四 21:00-22:00 1 李庆红（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 4, 'start_time' => '21:00', 'end_time' => '22:00', 'student_name' => '李庆红', 
     'coach_name' => '王', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 07:30-08:30 1 沈琦（孟）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '07:30', 'end_time' => '08:30', 'student_name' => '沈琦', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 10:00-11:00 1 明妈（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '明妈', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 10:00-11:00 2 张家骏（吴）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '张家骏', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周五 11:00-12:00 1 沈见高（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '沈见高', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 11:00-12:00 2 张小姐（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '张小姐', 
     'coach_name' => '徐', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周五 12:00-16:00 1 裘总用场
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '12:00', 'end_time' => '16:00', 'student_name' => '裘总用场', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周五 12:00-13:00 2 卲总（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '12:00', 'end_time' => '13:00', 'student_name' => '卲总', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 16:00-17:00 1 郑欣韵（黎）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '郑欣韵', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 16:00-17:00 2 惠天鹏（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '惠天鹏', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 17:00-18:00 2 monika儿子（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => 'monika儿子', 
     'coach_name' => '余', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周五 18:00-19:00 1 樊友谊1v2（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '樊友谊1v2', 
     'coach_name' => '王', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周五 18:00-19:00 2 小胡（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '小胡', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 19:00-22:00 1 阿姨
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 5, 'start_time' => '19:00', 'end_time' => '22:00', 'student_name' => '阿姨', 
     'coach_name' => '', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周五 19:00-20:00 2 沈总同（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '沈总同', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 20:00-21:00 2 余哥（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '余哥', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周五 21:00-22:00 2 ALEX（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 5, 'start_time' => '21:00', 'end_time' => '22:00', 'student_name' => 'ALEX', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 09:00-10:30 2A 团课（李）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 6, 'start_time' => '09:00', 'end_time' => '10:30', 'student_name' => '团课', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => false, 'remark' => ''],
    // 周六 09:30-11:30 1A 大童集训（袁）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 6, 'start_time' => '09:30', 'end_time' => '11:30', 'student_name' => '大童集训', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => false, 'remark' => ''],
    // 周六 10:00-11:30 2B 小童集训（孟）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 6, 'start_time' => '10:00', 'end_time' => '11:30', 'student_name' => '小童集训', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => false, 'remark' => ''],
    // 周六 10:30-11:30 1B 王天瑶（李）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 6, 'start_time' => '10:30', 'end_time' => '11:30', 'student_name' => '王天瑶', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 11:00-12:00 2A 周如是（徐）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 6, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '周如是', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 11:30-13:00 1 monika儿子（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '11:30', 'end_time' => '13:00', 'student_name' => 'monika儿子', 
     'coach_name' => '余', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 13:00-14:00 1 王玥瑶（孟）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '王玥瑶', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 13:00-14:00 2 余哥（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '余哥', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 14:00-15:00 1A 廖园（黎）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 6, 'start_time' => '14:00', 'end_time' => '15:00', 'student_name' => '廖园', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 14:00-15:00 1B 王逸昊（李）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 6, 'start_time' => '14:00', 'end_time' => '15:00', 'student_name' => '王逸昊', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 14:00-15:00 2A hong（余）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 6, 'start_time' => '14:00', 'end_time' => '15:00', 'student_name' => 'hong', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 14:00-15:00 2B 汤圆（徐）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 6, 'start_time' => '14:00', 'end_time' => '15:00', 'student_name' => '汤圆', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 15:00-16:00 1 姚若辰（袁）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '15:00', 'end_time' => '16:00', 'student_name' => '姚若辰', 
     'coach_name' => '袁', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 15:00-16:00 2 岩儿（余）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '15:00', 'end_time' => '16:00', 'student_name' => '岩儿', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 16:00-17:00 1 姚若辰（袁）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '姚若辰', 
     'coach_name' => '袁', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 16:00-17:00 2 高海燕儿子（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '高海燕儿子', 
     'coach_name' => '黎', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 17:00-18:00 2 高海燕儿子（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '高海燕儿子', 
     'coach_name' => '黎', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 18:00-19:00 1 廖艳佳（黎）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '廖艳佳', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 18:00-19:00 2 潘君怡（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '潘君怡', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 19:00-20:00 1A 王宇彦（徐）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 6, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '王宇彦', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 19:00-20:00 1B 章轶华（李）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 6, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '章轶华', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 19:00-20:30 2 金苑（吴）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '19:00', 'end_time' => '20:30', 'student_name' => '金苑', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周六 20:00-21:00 1 刘陌言（徐）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '刘陌言', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 21:00-22:30 1 蒋佳顺（吴）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 6, 'start_time' => '21:00', 'end_time' => '22:30', 'student_name' => '蒋佳顺', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周六 21:00-22:00 2 王旻芳（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 6, 'start_time' => '21:00', 'end_time' => '22:00', 'student_name' => '王旻芳', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 09:00-10:00 1A 沈安安（孟）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 7, 'start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '沈安安', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 09:00-10:00 2 畅畅（李）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '畅畅', 
     'coach_name' => '李', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 09:30-11:30 1B 大童集训（袁）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 7, 'start_time' => '09:30', 'end_time' => '11:30', 'student_name' => '大童集训', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => false, 'remark' => ''],
    // 周日 10:00-11:00 1A 米乐（孟）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '米乐', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 10:00-11:30 2A 小童集训（孟）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '11:30', 'student_name' => '小童集训', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => false, 'remark' => ''],
    // 周日 10:00-11:00 2B 明明（余）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '明明', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 11:00-12:00 1A 金洁 Lu1v2（李）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 7, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '金洁 Lu1v2', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 12:00-13:00 1 蛋宝（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '12:00', 'end_time' => '13:00', 'student_name' => '蛋宝', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 12:00-13:00 2 刘湉欣（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '12:00', 'end_time' => '13:00', 'student_name' => '刘湉欣', 
     'coach_name' => '黎', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 13:00-14:00 1 吴忠一家（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '吴忠一家', 
     'coach_name' => '余', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 13:00-14:00 2 郑侃、孟全丰1v2（袁）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '13:00', 'end_time' => '14:00', 'student_name' => '郑侃、孟全丰1v2', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 14:00-16:00 1 林总（余）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '14:00', 'end_time' => '16:00', 'student_name' => '林总', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 14:00-16:00 2 童蕙蕙（吴）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '14:00', 'end_time' => '16:00', 'student_name' => '童蕙蕙', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 16:00-17:00 1 张家骏（吴）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '张家骏', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 16:00-17:00 2A 岩儿（余）
    ['region' => '开发区场地', 'venue' => '2A', 'weekday' => 7, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '岩儿', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 16:30-17:30 2B 小腾腾（李）
    ['region' => '开发区场地', 'venue' => '2B', 'weekday' => 7, 'start_time' => '16:30', 'end_time' => '17:30', 'student_name' => '小腾腾', 
     'coach_name' => '李', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 17:00-18:00 1A 余哥（余）
    ['region' => '开发区场地', 'venue' => '1A', 'weekday' => 7, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '余哥', 
     'coach_name' => '余', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 17:00-18:00 1B 高律（袁）
    ['region' => '开发区场地', 'venue' => '1B', 'weekday' => 7, 'start_time' => '17:00', 'end_time' => '18:00', 'student_name' => '高律', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 18:00-19:00 1 赵颖涵（袁）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '赵颖涵', 
     'coach_name' => '袁', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 18:00-19:00 2 庞丽萍，边萍霞（黎）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '18:00', 'end_time' => '19:00', 'student_name' => '庞丽萍，边萍霞', 
     'coach_name' => '黎', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 19:00-20:00 1 王大君（袁）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '王大君', 
     'coach_name' => '袁', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周日 19:00-20:00 2 lily（徐）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => 'lily', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 20:00-21:00 1 李梦婕（王）
    ['region' => '开发区场地', 'venue' => '1', 'weekday' => 7, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '李梦婕', 
     'coach_name' => '王', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 20:00-22:00 2 沈院长（袁）
    ['region' => '开发区场地', 'venue' => '2', 'weekday' => 7, 'start_time' => '20:00', 'end_time' => '22:00', 'student_name' => '沈院长', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],

    // ============================== 世纪公园·龙安湖 ==============================
    // 周一 19:00-20:00 龙安湖 俞天堇（吴）
    ['region' => '世纪公园·龙安湖', 'venue' => '龙安湖', 'weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:00', 'student_name' => '俞天堇', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],
    // 周一 20:00-21:00 龙安湖 周磊（吴）
    ['region' => '世纪公园·龙安湖', 'venue' => '龙安湖', 'weekday' => 1, 'start_time' => '20:00', 'end_time' => '21:00', 'student_name' => '周磊', 
     'coach_name' => '吴', 'exclusive' => true, 'is_student' => true, 'remark' => ''],

    // ============================== 余之城球杨 ==============================
    // 周四 16:00-17:00 余之城 小听（徐）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 4, 'start_time' => '16:00', 'end_time' => '17:00', 'student_name' => '小听', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 09:00-10:00 余之城 吕沐含（孟）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 6, 'start_time' => '09:00', 'end_time' => '10:00', 'student_name' => '吕沐含', 
     'coach_name' => '孟', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 10:00-11:00 余之城 小玛（袁）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 6, 'start_time' => '10:00', 'end_time' => '11:00', 'student_name' => '小玛', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 11:00-12:00 余之城 高墨初（袁）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 6, 'start_time' => '11:00', 'end_time' => '12:00', 'student_name' => '高墨初', 
     'coach_name' => '袁', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周六 14:30-16:00 余之城 小童集训（王）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 6, 'start_time' => '14:30', 'end_time' => '16:00', 'student_name' => '小童集训', 
     'coach_name' => '王', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周日 14:00-15:00 余之城 小叶（徐）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 7, 'start_time' => '14:00', 'end_time' => '15:00', 'student_name' => '小叶', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],
    // 周日 15:00-16:00 余之城 张伦浩一对二（徐）
    ['region' => '余之城球杨', 'venue' => '余之城', 'weekday' => 7, 'start_time' => '15:00', 'end_time' => '16:00', 'student_name' => '张伦浩一对二', 
     'coach_name' => '徐', 'exclusive' => false, 'is_student' => true, 'remark' => ''],

    // ============================== 其它场地 ==============================
    // 周二 16:20-17:20 一小 一小（孟李）
    ['region' => '其它场地', 'venue' => '一小', 'weekday' => 2, 'start_time' => '16:20', 'end_time' => '17:20', 'student_name' => '一小', 
     'coach_name' => '孟李', 'exclusive' => true, 'is_student' => false, 'remark' => '15.50出发'],
    // 周三 14:55-16:30 信达 信达（李孟）
    ['region' => '其它场地', 'venue' => '信达', 'weekday' => 3, 'start_time' => '14:55', 'end_time' => '16:30', 'student_name' => '信达', 
     'coach_name' => '李孟', 'exclusive' => true, 'is_student' => false, 'remark' => ''],
    // 周四 16:20-17:20 一小 一小（孟李）
    ['region' => '其它场地', 'venue' => '一小', 'weekday' => 4, 'start_time' => '16:20', 'end_time' => '17:20', 'student_name' => '一小', 
     'coach_name' => '孟李', 'exclusive' => true, 'is_student' => false, 'remark' => '15.50出发'],
    // 周五 16:20-17:20 一小 一小（孟李）
    ['region' => '其它场地', 'venue' => '一小', 'weekday' => 5, 'start_time' => '16:20', 'end_time' => '17:20', 'student_name' => '一小', 
     'coach_name' => '孟李', 'exclusive' => true, 'is_student' => false, 'remark' => '15.50出发'],
    // 周六 16:00-18:00 一小 一小（孟）
    ['region' => '其它场地', 'venue' => '一小', 'weekday' => 6, 'start_time' => '16:00', 'end_time' => '18:00', 'student_name' => '一小', 
     'coach_name' => '孟', 'exclusive' => true, 'is_student' => false, 'remark' => '15.30出发'],
];
