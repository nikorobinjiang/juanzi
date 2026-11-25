<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GroupCourse;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MiniAppSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_signup_and_see_my_group_courses(): void
    {
        $course = $this->course(8);
        $customer = $this->customer('13800138000');

        $response = $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', [
            'name' => '张三',
            'people_count' => 2,
            'remark' => '希望周六下午',
        ], $this->auth($customer));

        $response->assertCreated()->assertJsonPath('data.people_count', 2);
        $this->assertSame(2, $course->fresh()->signed_up_count);

        $this->getJson('/api/miniapp/customer/signups', $this->auth($customer))
            ->assertOk()
            ->assertJsonPath('data.0.course.title', '拼课训练')
            ->assertJsonPath('data.0.status', 'joined');
    }

    public function test_duplicate_signup_is_rejected(): void
    {
        $course = $this->course(8);
        $customer = $this->customer('13800138001');
        $headers = $this->auth($customer);

        $payload = ['name' => '李四', 'people_count' => 1];

        $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', $payload, $headers)
            ->assertCreated();

        $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', $payload, $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activity');

        $this->assertSame(1, $course->fresh()->signed_up_count);
    }

    public function test_capacity_is_enforced(): void
    {
        $course = $this->course(2);
        $first = $this->customer('13800138002');
        $second = $this->customer('13800138003');

        $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', [
            'name' => '王五', 'people_count' => 2,
        ], $this->auth($first))->assertCreated();

        Sanctum::actingAs($second, ['customer']);

        $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', [
            'name' => '赵六', 'people_count' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('people_count');

        $this->assertSame(2, $course->fresh()->signed_up_count);
    }

    public function test_cancel_releases_capacity_and_allows_re_signup(): void
    {
        $course = $this->course(2);
        $first = $this->customer('13800138004');
        $second = $this->customer('13800138005');

        $signup = $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', [
            'name' => '钱七', 'people_count' => 2,
        ], $this->auth($first))->assertCreated()->json('data');

        Sanctum::actingAs($first, ['customer']);
        $this->postJson('/api/miniapp/customer/signups/'.$signup['id'].'/cancel', [])->assertOk();

        $this->assertSame(0, $course->fresh()->signed_up_count);

        Sanctum::actingAs($second, ['customer']);
        $this->postJson('/api/miniapp/activities/'.$course->share_token.'/signup', [
            'name' => '孙八', 'people_count' => 2,
        ])->assertCreated();

        $this->assertSame(2, $course->fresh()->signed_up_count);
    }

    private function course(int $capacity): GroupCourse
    {
        $organization = Organization::create(['code' => 'netx', 'name' => 'NetX 体育俱乐部']);

        return GroupCourse::create([
            'organization_code' => $organization->code,
            'title' => '拼课训练',
            'description' => '提升反手技术',
            'capacity' => $capacity,
            'status' => GroupCourse::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function customer(string $phone): Customer
    {
        return Customer::create([
            'name' => '测试客户',
            'phone' => $phone,
            'miniapp_openid' => 'openid-'.$phone,
        ]);
    }

    /** @return array<string, string> */
    private function auth(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('test', ['customer'])->plainTextToken];
    }
}
