<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\WechatBinding;
use App\Services\FixedScheduleService;
use App\Services\LessonReminderService;
use App\Services\WechatService;
use App\Support\BookingWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 给明天有课的学员 / 教练推送微信模板消息提醒
 *
 * 用法：
 *   php artisan bookings:remind                      # 推送明天的课程（每日 20:00 由调度器调用）
 *   php artisan bookings:remind --date=2026-09-21    # 推送指定日期的课程
 *   php artisan bookings:remind --dry-run            # 只预览不发送
 *   php artisan bookings:remind --org=tennis_a       # 只推某个机构
 *   php artisan bookings:remind --only=coach         # 只推教练（student / coach）
 *   php artisan bookings:remind --force              # 忽略幂等日志，重发当天已发过的提醒
 *   php artisan bookings:remind --per-lesson         # 学员每节课一条（默认按学员汇总成一条）
 *
 * 幂等：同一天同一人同一范围只发一次，重跑 --force 之前不会重复打扰用户。
 */
class RemindTomorrowLessons extends Command
{
    protected $signature = 'bookings:remind
        {--date= : 提醒哪一天的课程，默认明天（YYYY-MM-DD）}
        {--org= : 只推送指定机构 code，默认全部机构}
        {--only= : 只推送某个身份：student 学员 / coach 教练，默认两者都推}
        {--dry-run : 只预览卡片内容，不真正发送}
        {--force : 忽略幂等日志，强制重发}
        {--per-lesson : 学员每节课一条消息（默认按学员汇总成一条）}';

    protected $description = '通过微信服务号给明天有课的学员和教练推送上课提醒';

    public function handle(LessonReminderService $reminders, WechatService $wechat): int
    {
        $date = $reminders->dateOf($this->option('date') ?: null);
        $orgCode = trim((string) $this->option('org'));
        $dryRun = (bool) $this->option('dry-run');
        $only = strtolower(trim((string) $this->option('only')));

        if (! $dryRun && ! $wechat->configured()) {
            $this->warn('未配置微信服务号（WECHAT_APP_ID / WECHAT_APP_SECRET），本次不发送');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '上课日期：%s ｜ 机构：%s ｜ 身份：%s ｜ 模式：%s',
            $date->toDateString(),
            $orgCode === '' ? '全部' : $orgCode,
            $only === '' ? '学员 + 教练' : $only,
            $dryRun ? '预览（不发送）' : '发送'
        ));

        // 固定场靠「打开页面」懒补齐，定时推送当天可能没人打开过系统；
        // 查询前先为相关机构补齐固定场窗口，保证明天的固定课不漏推（幂等，可重复执行）
        $this->ensureFixedSchedules($orgCode);

        try {
            $failureCount = 0;

            if ($only !== WechatBinding::TYPE_COACH) {
                $failureCount += $this->report(
                    '学员',
                    $this->pipeline('student', $reminders, $date, $orgCode, $dryRun),
                    $reminders
                );
            }

            if ($only !== WechatBinding::TYPE_STUDENT) {
                $failureCount += $this->report(
                    '教练',
                    $this->pipeline('coach', $reminders, $date, $orgCode, $dryRun),
                    $reminders
                );
            }

            return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('推送中断：'.$e->getMessage());

            Log::error('上课提醒执行失败', [
                'remind_date' => $date->toDateString(),
                'organization_code' => $orgCode,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * 为相关机构补齐固定场展开窗口
     *
     * 单个机构补齐失败只记日志，不阻断其余机构与推送本身。
     */
    private function ensureFixedSchedules(string $orgCode): void
    {
        $codes = Organization::query()
            ->when($orgCode !== '', fn ($query) => $query->where('code', $orgCode))
            ->pluck('code');

        foreach ($codes as $code) {
            try {
                app(FixedScheduleService::class)->ensureWindow((string) $code);
            } catch (\Throwable $e) {
                Log::warning('上课提醒前固定场补齐失败', [
                    'organization_code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 跑一条流水线；总开关关闭时（预览除外）直接跳过并记日志
     *
     * @return array{targets: int, sent: int, failed: int, skipped: int}
     */
    private function pipeline(string $type, LessonReminderService $reminders, \Carbon\Carbon $date, string $orgCode, bool $dryRun): array
    {
        $enabled = $type === WechatBinding::TYPE_COACH
            ? (bool) config('wechat.remind.coach_enabled', false)
            : (bool) config('wechat.remind.student_enabled', false);

        if (! $dryRun && ! $enabled) {
            $this->warn(sprintf(
                '%s端提醒总开关未打开（WECHAT_%s_REMIND_ENABLED=false），跳过',
                $type === WechatBinding::TYPE_COACH ? '教练' : '学员',
                $type === WechatBinding::TYPE_COACH ? 'COACH_' : ''
            ));

            return ['targets' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $options = [
            'dry_run' => $dryRun,
            'force' => (bool) $this->option('force'),
            'per_lesson' => (bool) $this->option('per-lesson'),
        ];

        return $type === WechatBinding::TYPE_COACH
            ? $reminders->remindCoaches($date, $orgCode, $options)
            : $reminders->remindStudents($date, $orgCode, $options);
    }

    /**
     * 打印统计并返回失败数
     *
     * @param  array{targets: int, sent: int, failed: int, skipped: int}  $stats
     */
    private function report(string $label, array $stats, LessonReminderService $reminders): int
    {
        if ($stats['targets'] === 0) {
            $this->line(sprintf('  · %s：明日无人需提醒', $label));

            return 0;
        }

        foreach ($reminders->messages() as $message) {
            $this->line($message);
        }

        $this->info(sprintf(
            '  · %s：命中 %d 人 ｜ 已发 %d ｜ 失败 %d ｜ 跳过 %d',
            $label, $stats['targets'], $stats['sent'], $stats['failed'], $stats['skipped']
        ));

        Log::info('上课提醒统计', [
            'target_type' => $label,
            'remind_at' => BookingWindow::now()->toDateTimeString(),
            'stats' => $stats,
        ]);

        return $stats['failed'];
    }
}
