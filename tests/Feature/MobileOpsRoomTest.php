<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모바일 현장 상황실 — 현장에서 휴대폰으로 원문 기록을 보고 올린다.
 */
class MobileOpsRoomTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        $employee = Employee::create([
            'site_id' => $this->site->id,
            'name' => 'Manager '.$role,
            'email' => $role.'@example.com',
            'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'access_role' => $role,
            'account_status' => 'active',
            'employee_id' => $employee->id,
        ]);
    }

    private function batch(string $raw): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id,
            'source' => 'paste',
            'raw_text' => $raw,
            'parsed_count' => 1,
            'actionable_count' => 1,
        ]);
    }

    public function test_page_requires_login(): void
    {
        $this->get('/attendance-app/ops-room')->assertRedirect('/login');
    }

    public function test_page_lists_the_raw_records(): void
    {
        $this->batch('천장 배관 12개 완료');

        $res = $this->actingAs($this->user('site_manager'))->get('/attendance-app/ops-room');

        $res->assertStatus(200);
        $res->assertSee('천장 배관 12개 완료', false);   // 목록은 화면이 그린다(JSON 으로 실려 나간다)
        $res->assertSee('Arizona Site');
        $res->assertSee('현장 기록');
    }

    public function test_manager_gets_edit_controls_and_others_do_not(): void
    {
        $this->batch('원문');

        $this->actingAs($this->user('site_manager'))->get('/attendance-app/ops-room')
            ->assertStatus(200)->assertSee('var CAN_MANAGE = true;', false);

        $this->actingAs($this->user('foreman'))->get('/attendance-app/ops-room')
            ->assertStatus(200)->assertSee('var CAN_MANAGE = false;', false);
    }

    public function test_the_screen_says_what_to_do_before_anything_else(): void
    {
        // 화면을 여는 순간 «지금 뭘 해야 하나» 가 읽혀야 한다.
        $this->actingAs($this->user('admin'))->get('/attendance-app/ops-room')
            ->assertStatus(200)
            ->assertSee('눌러서 말하기')
            ->assertSee('글로 쓰기');
    }

    public function test_home_links_to_the_ops_room(): void
    {
        $this->actingAs($this->user('site_manager'))->get('/attendance-app')
            ->assertStatus(200)
            ->assertSee(route('attendance-app.ops-room'), false)
            ->assertSee('현장 상황실');
    }
}
