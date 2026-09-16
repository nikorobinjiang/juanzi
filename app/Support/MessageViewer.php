<?php

namespace App\Support;

use App\Models\User;

/**
 * 聊天记录可见范围（白名单）
 *
 * 默认每个账号只能看到自己与助手的对话（ChatController::history 按 user_id 过滤）；
 * 这里登记需要「查看本机构全部聊天记录」的账号，供后台核对员工/学员的对话记录。
 *
 * 判定必须同时匹配机构与登录名：username 只在机构内唯一，跨机构可能重名，
 * 只判用户名会让其他机构的同名账号意外获得权限，所以机构为空时直接拒绝。
 *
 * 机构隔离不受影响：命中白名单只是不再按 user_id 过滤，
 * 数据范围仍由 OrganizationScope 限定在本机构内，跨机构数据依然完全不可见。
 *
 * 以后要给别的账号开权限，在 WHITELIST 里加一行即可，无需改动 Controller。
 */
class MessageViewer
{
    /**
     * 白名单：[机构 code, 登录名]，两项同时匹配才生效
     */
    private const WHITELIST = [
        ['organization_code' => 'alan_tennis', 'username' => 'juanzi'],
    ];

    /**
     * 该账号能否查看本机构全部聊天记录
     */
    public static function seesAllMessages(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $org = trim((string) $user->organization_code);
        $username = trim((string) $user->username);

        // 机构或登录名为空：无法可靠判定归属，一律拒绝
        if ($org === '' || $username === '') {
            return false;
        }

        foreach (self::WHITELIST as $allowed) {
            if ($allowed['organization_code'] === $org && $allowed['username'] === $username) {
                return true;
            }
        }

        return false;
    }
}
