<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 오류 로그를 아무나 보면 안 된다.
 *
 * 이 파일에는 예외와 함께 이메일·요청 내용이 섞여 나온다. 주소가 길어서 안 들키는 것은
 * 잠금장치가 아니다 — 남의 회사 데이터를 담을 제품이라면 더욱.
 */
class DebugLogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stranger_cannot_read_the_log(): void
    {
        $this->get('/debug-logs-sec-53298bfd9a')->assertRedirect('/login');
    }

    public function test_an_ordinary_worker_cannot_read_the_log(): void
    {
        $worker = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($worker)->get('/debug-logs-sec-53298bfd9a')->assertForbidden();
    }

    public function test_an_admin_can_read_it(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($admin)->get('/debug-logs-sec-53298bfd9a')->assertOk();
    }
}
