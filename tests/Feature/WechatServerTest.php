<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WechatServerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'yuekeRemind2026';

    protected function setUp(): void
    {
        parent::setUp();

        config(['wechat.server.token' => self::TOKEN]);
    }

    public function test_valid_signature_echoes_challenge(): void
    {
        $query = $this->signedQuery();

        $this->get('/wechat/server?'.http_build_query($query + ['echostr' => 'hello-wechat']))
            ->assertOk()
            ->assertSee('hello-wechat');
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $query = $this->signedQuery();
        $query['signature'] = str_repeat('0', 40);

        $this->get('/wechat/server?'.http_build_query($query + ['echostr' => 'x']))
            ->assertForbidden();
    }

    public function test_missing_token_disables_endpoint(): void
    {
        config(['wechat.server.token' => '']);

        $this->get('/wechat/server?'.http_build_query($this->signedQuery() + ['echostr' => 'x']))
            ->assertStatus(503);
    }

    public function test_message_push_is_acknowledged(): void
    {
        $this->post('/wechat/server?'.http_build_query($this->signedQuery()))
            ->assertOk()
            ->assertSee('success');
    }

    public function test_message_push_with_bad_signature_is_rejected(): void
    {
        $query = $this->signedQuery();
        $query['nonce'] = 'tampered';

        $this->post('/wechat/server?'.http_build_query($query))
            ->assertForbidden();
    }

    /** 关注事件：被动回复欢迎语与学员/教练绑定链接 */
    public function test_subscribe_event_replies_with_bind_links(): void
    {
        $xml = <<<'XML'
<xml><ToUserName><![CDATA[gh_test_account]]></ToUserName><FromUserName><![CDATA[oSUB_openid_1]]></FromUserName><CreateTime>1730000000</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[subscribe]]></Event></xml>
XML;

        $response = $this->call(
            'POST',
            '/wechat/server?'.http_build_query($this->signedQuery()),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml'],
            $xml
        );
        $response->assertOk();

        $content = $response->baseResponse->getContent() ?? '';
        $this->assertStringContainsString('oSUB_openid_1', $content);
        $this->assertStringContainsString('gh_test_account', $content);
        $this->assertStringContainsString(url('/wechat/bind'), $content);
        $this->assertStringContainsString(url('/wechat/bind?role=coach'), $content);
    }

    /**
     * @return array{signature: string, timestamp: string, nonce: string}
     */
    private function signedQuery(): array
    {
        $timestamp = '1730000000';
        $nonce = 'abc123';
        $parts = [self::TOKEN, $timestamp, $nonce];
        sort($parts, SORT_STRING);

        return [
            'signature' => sha1(implode('', $parts)),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ];
    }
}
