<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 채팅방으로 들어가는 길이 화면에 있다.
 *
 * 기능을 다 만들어 놓고도 David 는 채팅방을 열지 못했다 — 관리 화면에는 방 목록만
 * 있고 "열기" 가 없었고, 사이드바에는 <b>메신저 관리</b>(관리자용)만 있었지 대화
 * 자체로 가는 항목이 없었다. 주소를 직접 쳐야만 들어갈 수 있는 기능은 없는 기능이다.
 */
class MessengerEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_has_a_way_into_the_chat_itself(): void
    {
        $company = Company::create(['code' => 'ENTRY-CO', 'name' => 'Entry Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'ENTRY', 'name' => '현장', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => 'D', 'last_name' => 'Cho', 'email' => 'entry@example.com',
            'employment_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'admin', 'account_status' => 'active',
        ]);

        $html = $this->actingAs($admin)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-view="messenger"', $html,
            '사이드바에 채팅방으로 들어가는 항목이 없습니다 — 주소를 직접 쳐야만 들어갈 수 있습니다.');
        $this->assertStringContainsString('/attendance-app/messages', $html,
            '메신저 화면이 실제 대화 주소를 열지 않습니다.');
        $this->assertStringContainsString('data-mobile-view="messenger"', $html,
            '폰에서 채팅으로 갈 길이 없습니다 — 현장 사용의 90%가 폰입니다.');
    }

    public function test_the_room_list_can_open_a_room(): void
    {
        $script = (string) file_get_contents(public_path('js/admin-messenger.js'));

        $this->assertStringContainsString('enterRoom', $script,
            '방 목록에서 그 방으로 들어가는 버튼이 없습니다.');
        $this->assertStringContainsString('/attendance-app/messages/', $script);
    }
}
