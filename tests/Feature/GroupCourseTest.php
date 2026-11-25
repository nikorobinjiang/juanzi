<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_publish_and_publicly_read_activity(): void
    {
        [$user, $headers] = $this->staff('netx', 'NetX 体育俱乐部', 'admin');

        $created = $this->postJson('/api/miniapp/activities', [
            'title' => '网球反手提升训练',
            'description' => '多球训练提升反手技术',
            'venue' => 'NetX Sports 双网体育俱乐部',
            'contact_phone' => '19057155603',
            'address' => '临平大道 507 号',
            'capacity' => 8,
        ], $headers);

        $created->assertCreated()->assertJsonPath('data.status', 'draft');

        $activity = $created->json('data');
        $this->assertNotEmpty($activity['share_token']);

        $this->postJson('/api/miniapp/activities/'.$activity['id'].'/publish', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->getJson('/api/miniapp/activities/'.$activity['share_token'])
            ->assertOk()
            ->assertJsonPath('data.title', '网球反手提升训练')
            ->assertJsonPath('data.organization_name', 'NetX 体育俱乐部')
            ->assertJsonPath('data.signups', []);
    }

    public function test_staff_cannot_update_activity_from_another_organization(): void
    {
        [, $headers] = $this->staff('netx', 'NetX 体育俱乐部', 'admin');
        [$other] = $this->staff('other', '其他机构', 'other-admin');

        $created = $this->postJson('/api/miniapp/activities', [
            'title' => '原活动',
            'capacity' => 4,
        ], $headers);

        Sanctum::actingAs($other, ['staff']);

        $this->putJson('/api/miniapp/activities/'.$created->json('data.id'), [
            'title' => '被篡改的活动',
            'capacity' => 4,
        ])->assertUnprocessable();
    }

    public function test_staff_routes_require_sanctum_token(): void
    {
        $this->getJson('/api/miniapp/activities')->assertUnauthorized();
        $this->postJson('/api/miniapp/activities', [])->assertUnauthorized();
    }

    /** @return array{App\Models\User, array<string, string>} */
    private function staff(string $code, string $name, string $username): array
    {
        $organization = Organization::create(['code' => $code, 'name' => $name]);
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'organization_code' => $organization->code,
            'role' => User::ROLE_ORG_ADMIN,
            'password' => Hash::make('secret123'),
        ]);

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('test', ['staff'])->plainTextToken]];
    }
}
