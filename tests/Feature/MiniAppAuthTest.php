<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MiniAppAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wechat.miniapp.app_id' => 'wx-miniapp-test',
            'wechat.miniapp.secret' => 'miniapp-secret',
        ]);
    }

    public function test_staff_can_login_with_organization_and_use_staff_token(): void
    {
        $organization = Organization::create(['code' => 'netx', 'name' => 'NetX 体育俱乐部']);
        User::create([
            'name' => '管理员',
            'username' => 'admin',
            'organization_code' => $organization->code,
            'role' => User::ROLE_ORG_ADMIN,
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/miniapp/auth/staff', [
            'organization_code' => 'netx',
            'username' => 'admin',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.organization_name', 'NetX 体育俱乐部')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);

        $headers = ['Authorization' => 'Bearer '.$response->json('data.token')];

        $this->getJson('/api/miniapp/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.role', 'staff');

        $this->getJson('/api/miniapp/activities', $headers)->assertOk();
    }

    public function test_staff_login_rejects_wrong_password(): void
    {
        $organization = Organization::create(['code' => 'netx', 'name' => 'NetX 体育俱乐部']);
        User::create([
            'name' => '管理员',
            'username' => 'admin',
            'organization_code' => $organization->code,
            'role' => User::ROLE_ORG_ADMIN,
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/miniapp/auth/staff', [
            'organization_code' => 'netx',
            'username' => 'admin',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_customer_login_creates_customer_and_customer_token(): void
    {
        Http::fake([
            '*sns/jscode2session*' => Http::response([
                'openid' => 'mini-openid-1',
                'unionid' => 'union-1',
                'session_key' => 'should-never-be-logged',
            ]),
            '*cgi-bin/token*' => Http::response([
                'access_token' => 'mini-access-token',
                'expires_in' => 7200,
            ]),
            '*getuserphonenumber*' => Http::response([
                'errcode' => 0,
                'phone_info' => ['phoneNumber' => '13800138000'],
            ]),
        ]);

        $response = $this->postJson('/api/miniapp/auth/customer', [
            'code' => 'wx-login-code',
            'phone_code' => 'phone-auth-code',
            'name' => '张三',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token', 'token_type', 'customer']]);

        $customer = Customer::where('phone', '13800138000')->firstOrFail();
        $this->assertSame('mini-openid-1', $customer->miniapp_openid);
        $this->assertSame('union-1', $customer->unionid);

        $this->getJson('/api/miniapp/me', [
            'Authorization' => 'Bearer '.$response->json('data.token'),
        ])->assertOk()->assertJsonPath('data.role', 'customer');
    }

    public function test_customer_login_falls_back_to_manually_entered_phone(): void
    {
        Http::fake([
            '*sns/jscode2session*' => Http::response(['openid' => 'mini-openid-2']),
            '*getuserphonenumber*' => Http::response(['errcode' => 40001, 'errmsg' => 'invalid code']),
        ]);

        $response = $this->postJson('/api/miniapp/auth/customer', [
            'code' => 'wx-login-code',
            'phone' => '13900139000',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', ['phone' => '13900139000', 'miniapp_openid' => 'mini-openid-2']);
    }
}
