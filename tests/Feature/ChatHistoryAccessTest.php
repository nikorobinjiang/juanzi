<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Support\MessageViewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 聊天记录可见范围测试
 *
 * 默认每个账号只能看到自己与助手的对话；
 * 白名单账号（alan_tennis 的 juanzi）能看到本机构全部账号的对话，
 * 并额外拿到 sender / is_mine 两个字段用于前端标注发送人。
 * 机构隔离不受影响：白名单账号同样看不到其他机构的数据。
 */
class ChatHistoryAccessTest extends TestCase
{
    use RefreshDatabase;

    /** 造一个指定机构与登录名的账号（密码明文，模型 hashed cast 自动哈希） */
    private function makeUser(string $username, string $org): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'password' => 'secret123',
            'organization_code' => $org,
        ]);
    }

    /** 造一条归属指定账号的消息（显式传机构，摆脱登录态依赖） */
    private function makeMessage(User $user, string $content, string $role = 'user'): Message
    {
        return Message::create([
            'role' => $role,
            'type' => 'text',
            'content' => $content,
            'organization_code' => $user->organization_code,
            'user_id' => $user->id,
        ]);
    }

    /** 白名单判定：机构与登录名必须同时匹配 */
    public function test_whitelist_requires_matching_org_and_username(): void
    {
        $this->assertTrue(MessageViewer::seesAllMessages($this->makeUser('juanzi', 'alan_tennis')));

        // 其他机构的同名账号不生效（username 只在机构内唯一）
        $this->assertFalse(MessageViewer::seesAllMessages($this->makeUser('juanzi', 'tennis_a')));
        // 本机构其他账号不生效
        $this->assertFalse(MessageViewer::seesAllMessages($this->makeUser('wang', 'alan_tennis')));
        $this->assertFalse(MessageViewer::seesAllMessages(null));
    }

    /** 白名单账号：看到本机构全部账号的对话，并带 sender / is_mine */
    public function test_whitelisted_user_sees_all_messages_of_own_org(): void
    {
        $viewer = $this->makeUser('juanzi', 'alan_tennis');
        $other = $this->makeUser('wang', 'alan_tennis');

        $mine = $this->makeMessage($viewer, '给小明约明天上午10点');
        $theirs = $this->makeMessage($other, '帮我改到下午');
        $reply = $this->makeMessage($other, '已改到下午 3 点', 'assistant');

        $this->actingAs($viewer);
        $messages = $this->getJson('/api/messages?limit=50')->assertOk()->json('messages');

        $this->assertSame([$mine->id, $theirs->id, $reply->id], array_column($messages, 'id'));

        $this->assertTrue($messages[0]['is_mine']);
        $this->assertSame('juanzi', $messages[0]['sender']);

        $this->assertFalse($messages[1]['is_mine']);
        $this->assertSame('wang', $messages[1]['sender']);
        // 助手回复归属发起对话的账号，同样标注
        $this->assertFalse($messages[2]['is_mine']);
        $this->assertSame('wang', $messages[2]['sender']);
    }

    /** 普通账号：仍然只看自己的消息，且返回结构里没有 sender / is_mine */
    public function test_normal_user_sees_only_own_messages_without_sender_fields(): void
    {
        $alice = $this->makeUser('alice', 'alan_tennis');
        $bob = $this->makeUser('bob', 'alan_tennis');

        $this->makeMessage($alice, '我的消息');
        $this->makeMessage($bob, '别人的消息');

        $this->actingAs($alice);
        $messages = $this->getJson('/api/messages?limit=50')->assertOk()->json('messages');

        $this->assertCount(1, $messages);
        $this->assertSame('我的消息', $messages[0]['content']);
        $this->assertArrayNotHasKey('sender', $messages[0]);
        $this->assertArrayNotHasKey('is_mine', $messages[0]);
    }

    /** 其他机构即使存在同名账号，也不会获得权限 */
    public function test_same_username_in_other_org_has_no_access(): void
    {
        $viewer = $this->makeUser('juanzi', 'tennis_a');
        $other = $this->makeUser('bob', 'tennis_a');

        $this->makeMessage($viewer, '我的消息');
        $this->makeMessage($other, '别人的消息');

        $this->actingAs($viewer);
        $messages = $this->getJson('/api/messages?limit=50')->assertOk()->json('messages');

        $this->assertCount(1, $messages);
        $this->assertSame('我的消息', $messages[0]['content']);
    }

    /** 白名单账号也看不到其他机构的数据（机构隔离不因权限放开） */
    public function test_whitelisted_user_cannot_see_other_org_messages(): void
    {
        $viewer = $this->makeUser('juanzi', 'alan_tennis');
        $mate = $this->makeUser('wang', 'alan_tennis');
        $outsider = $this->makeUser('outsider', 'tennis_a');

        $this->makeMessage($viewer, '本机构·我');
        $this->makeMessage($mate, '本机构·同事');
        $this->makeMessage($outsider, '别的机构');

        $this->actingAs($viewer);
        $messages = $this->getJson('/api/messages?limit=50')->assertOk()->json('messages');

        $this->assertCount(2, $messages);
        $this->assertSame(['本机构·我', '本机构·同事'], array_column($messages, 'content'));
    }

    /** 轮询增量（after_id）：他人新产生的消息也能拉到 */
    public function test_after_id_includes_other_users_new_messages(): void
    {
        $viewer = $this->makeUser('juanzi', 'alan_tennis');
        $other = $this->makeUser('wang', 'alan_tennis');

        $first = $this->makeMessage($viewer, '第一条');
        $newer = $this->makeMessage($other, '同事的新消息');

        $this->actingAs($viewer);
        $messages = $this->getJson('/api/messages?after_id='.$first->id)->assertOk()->json('messages');

        $this->assertCount(1, $messages);
        $this->assertSame($newer->id, $messages[0]['id']);
        $this->assertSame('wang', $messages[0]['sender']);
        $this->assertFalse($messages[0]['is_mine']);
    }

    /** 本机构内 user_id 为空的历史消息也会出现，但没有归属账号，sender 为 null */
    public function test_message_without_user_id_shows_without_sender(): void
    {
        $viewer = $this->makeUser('juanzi', 'alan_tennis');

        $legacy = Message::create([
            'role' => 'user',
            'type' => 'text',
            'content' => '无归属的历史消息',
            'organization_code' => 'alan_tennis',
        ]);

        $this->actingAs($viewer);
        $messages = $this->getJson('/api/messages?limit=50')->assertOk()->json('messages');

        $this->assertCount(1, $messages);
        $this->assertSame($legacy->id, $messages[0]['id']);
        $this->assertNull($messages[0]['sender']);
        $this->assertFalse($messages[0]['is_mine']);
    }
}
