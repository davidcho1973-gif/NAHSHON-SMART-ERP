<?php

namespace Tests\Feature;

use App\Livewire\FieldApp\FieldCommandApp;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireFieldCommandAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_command_app_requires_login(): void
    {
        // 현장앱은 기존 현장(Site)을 만들고 지우는 화면이다 — 미인증 공개면
        // 방문자가 현장과 딸린 기록을 연쇄 삭제할 수 있어 로그인 뒤로 옮겼다.
        $this->get('/field-app')->assertRedirect('/login');
    }

    public function test_field_command_app_can_be_rendered(): void
    {
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $this->actingAs($user);

        $response = $this->get('/field-app');
        $response->assertOk();
    }

    public function test_livewire_trade_counter_and_tab_switching_work(): void
    {
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $this->actingAs($user);

        // 출퇴근은 이제 정식 대장(attendance_logs)으로 간다 — 등록된 작업자를 특정해야 한다.
        // 예전의 이름만 적는 별도 대장(field_commute_logs)은 급여·집계가 모르는 기록이었다.
        $site = Site::create(['code' => 'AZ-01', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $emp = Employee::create([
            'name' => '김반장', 'employee_number' => 'E-001', 'employment_status' => 'active', 'site_id' => $site->id,
        ]);

        Livewire::test(FieldCommandApp::class)
            ->assertSet('activeTab', 'report')
            ->call('setTab', 'qr')
            ->assertSet('activeTab', 'qr')
            ->call('setTab', 'report')
            ->call('incrementTrade', 'elec')
            ->set('commute_worker_name', '김반장')
            ->call('recordCommute', 'in')
            ->assertSet('toastMessage', fn (?string $m): bool => str_contains((string) $m, '출근 기록 완료'))
            ->call('addEquipment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_logs', [
            'site_id' => $site->id,
            'employee_id' => $emp->id,
            'event_type' => 'clock_in',
            'source' => 'field_app',
        ]);
        // 정식 대장으로 갔으므로 급여 타임시트까지 자동으로 이어진다.
        $this->assertDatabaseHas('payroll_timesheets', [
            'employee_id' => $emp->id,
            'source' => 'attendance_logs',
        ]);
    }

    public function test_dynamic_trade_and_site_management_edit_delete_create(): void
    {
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $this->actingAs($user);

        // 1. Dynamic Trade Add & Edit & Remove
        Livewire::test(FieldCommandApp::class)
            ->set('new_trade_name', '폼목수')
            ->set('new_trade_icon', '🪵')
            ->call('addTrade')
            ->call('editTrade', 'elec')
            ->set('editing_trade_name', '전기/통신')
            ->call('updateTrade')
            ->call('removeTrade', 'mason')
            ->assertHasNoErrors();

        // 2. Dynamic Site Create & Edit & Delete
        $site = Site::query()->create(['name' => '평택 P4 현장', 'code' => 'PT-04', 'status' => 'active']);

        Livewire::test(FieldCommandApp::class)
            ->set('new_site_name', '평택 P5 신축현장')
            ->set('new_site_code', 'PT-05')
            ->call('createSite')
            ->call('editSite', $site->id)
            ->set('editing_site_name', '평택 P4 리노베이션 현장')
            ->call('updateSite')
            ->call('deleteSite', $site->id)
            ->assertHasNoErrors();
    }

    public function test_ai_drawing_analysis_and_blueprint_knowledge_qa(): void
    {
        $user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites']);
        $this->actingAs($user);

        Livewire::test(FieldCommandApp::class)
            ->call('setTab', 'knowledge')
            ->assertSet('activeTab', 'knowledge')
            ->set('new_drawing_title', 'C동 3층 기계실 주배관 평면도')
            ->set('new_drawing_category', 'MEP 배관/전기 도면')
            ->call('uploadAndAnalyzeDrawing')
            ->call('selectDrawing', 0)
            ->set('qa_question', 'A동 2층 배관 서포트 간격 및 용접 주의사항 알려줘.')
            ->call('askDrawingQuestion')
            ->assertHasNoErrors();
    }
}
