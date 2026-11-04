<?php

namespace App\Console\Commands;

use App\Services\WechatService;
use Illuminate\Console\Command;

/**
 * 向管理员微信发送一条「课程预约成功通知」测试模板消息
 *
 * 用法：
 *   php artisan wechat:test-template                   # openid 取 WECHAT_ADMIN_OPENID
 *   php artisan wechat:test-template --openid=oXXXX    # 临时指定 openid
 *   php artisan wechat:test-template --dry-run         # 只打印卡片数据，不真正发送
 *
 * 目的：验证服务号模板消息链路（access_token -> 发送接口）与模板字段是否可用。
 */
class SendTemplateMessageTest extends Command
{
    protected $signature = 'wechat:test-template
        {--openid= : 接收测试消息的微信 openid，默认取 WECHAT_ADMIN_OPENID}
        {--dry-run : 只展示将发送的字段，不调用微信接口}';

    protected $description = '发送一条「课程预约成功通知」测试模板消息到管理员微信';

    public function handle(WechatService $wechat): int
    {
        $templateId = trim((string) config('wechat.templates.booking_success.id'));
        $openid = trim((string) ($this->option('openid') ?: config('wechat.admin_openid')));

        if ($templateId === '') {
            $this->error('未配置模板 ID（WECHAT_BOOKING_TEMPLATE_ID）');

            return self::FAILURE;
        }

        // thing 类字段限 20 字符，time4 需带时间格式
        $data = [
            'thing1' => ['value' => '青少年网球启蒙课'],
            'time4' => ['value' => '2026年11月5日 18:30'],
            'thing5' => ['value' => '王小明'],
            'thing9' => ['value' => '李教练'],
        ];

        $this->info('模板：'.($templateId === config('wechat.templates.booking_success.id') ? 'booking_success（课程预约成功通知）' : $templateId));
        $this->info('openid：'.($openid === '' ? '（未配置）' : $openid));

        foreach ($data as $key => $field) {
            $this->line(sprintf('  %s（%s）：%s', $key, (string) config('wechat.templates.booking_success.fields.'.$key), $field['value']));
        }

        if ((bool) $this->option('dry-run')) {
            $this->comment('dry-run：未发送');

            return self::SUCCESS;
        }

        if ($openid === '') {
            $this->error('未配置管理员 openid（WECHAT_ADMIN_OPENID）：请关注公众号，回调会回复你的 openid，回填 .env 后重试');

            return self::FAILURE;
        }

        $result = $wechat->sendTemplateMessage($openid, $templateId, $data, url((string) config('wechat.templates.booking_success.url', '')));

        if ($result['ok']) {
            $this->info('发送成功：msgid='.($result['msgid'] ?? ''));

            return self::SUCCESS;
        }

        $this->error(sprintf('发送失败：errcode=%s errmsg=%s', $result['errcode'], $result['errmsg']));

        if (in_array($result['errcode'], ['43004', '40003'], true)) {
            $this->warn('该 openid 未关注公众号或无效：请用管理员微信关注公众号，回调会回复 openid，回填 .env 后重试');
        }

        return self::FAILURE;
    }
}
