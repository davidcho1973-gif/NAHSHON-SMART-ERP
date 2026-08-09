<?php

namespace Tests\Feature;

use App\Livewire\FieldApp\FieldCommandApp;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireFieldCommandAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_command_app_can_be_accessed_by_guest_without_auth_wall(): void
    {
        $response = $this->get('/field-app');
        $response->assertOk();
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

        // 현장이 있어야 출퇴근이 기록된다 — 없으면 recordCommute 가 경고만 남기고 돌아간다.
        $site = Site::create(['code' => 'AZ-01', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        Livewire::test(FieldCommandApp::class)
            ->assertSet('activeTab', 'report')
            ->call('setTab', 'qr')
            ->assertSet('activeTab', 'qr')
            ->call('setTab', 'report')
            ->call('incrementTrade', 'elec')
            ->call('recordCommute', 'in')
            // 기록 결과는 toastMessage 로 알린다. 시각이 붙으므로 완전일치가 아니라 포함으로 본다.
            ->assertSet('toastMessage', fn (?string $m): bool => str_contains((string) $m, '출근 기록 완료'))
            ->call('addEquipment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('field_commute_logs', [
            'site_id' => $site->id,
            'type' => 'in',
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
