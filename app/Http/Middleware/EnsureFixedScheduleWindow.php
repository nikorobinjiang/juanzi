<?php

namespace App\Http\Middleware;

use App\Services\FixedScheduleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 打开页面时自动补齐固定场（本周 + 下周）
 *
 * 固定场只提前展开两周，需要一个滚动补齐的触发点。服务器 cron 不可控，
 * 因此挂在已登录请求上：每个机构 30 分钟最多补齐一次（Cache::add 保证并发下只跑一次），
 * 补齐失败只记日志并清掉节流标记，绝不影响页面与接口。
 */
class EnsureFixedScheduleWindow
{
    /** 每个机构的补齐节流时间（分钟） */
    private const THROTTLE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $orgCode = (string) (auth('web')->user()?->organization_code ?? '');

        if ($orgCode !== '') {
            $this->ensure($orgCode);
        }

        return $next($request);
    }

    /**
     * 按机构补齐固定场窗口（幂等：已存在的记录不会重复生成）
     */
    private function ensure(string $orgCode): void
    {
        $key = 'fixed:ensure:'.$orgCode;

        // 节流窗口内已有请求补齐过 → 直接跳过（Cache::add 返回 false 表示 key 已存在）
        if (! Cache::add($key, 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        try {
            app(FixedScheduleService::class)->ensureWindow($orgCode);
        } catch (\Throwable $e) {
            // 补齐失败时清掉节流标记，下次打开页面重试
            Cache::forget($key);

            Log::warning('固定场自动补齐失败', [
                'organization_code' => $orgCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
