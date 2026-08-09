<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_google_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Continue with Google');
    }

    public function test_old_admin_login_url_lands_on_the_single_login_page(): void
    {
        // 로그인 창이 두 개면 어디로 들어가야 하는지 매번 헷갈린다. 이제 하나다.
        $this->get('/admin/login')->assertRedirect('/');
    }
}
