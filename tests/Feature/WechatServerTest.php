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
