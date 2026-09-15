<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // 首页是登录后才可见的业务页：未登录访问应跳转登录页
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
