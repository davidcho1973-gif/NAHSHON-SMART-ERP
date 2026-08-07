<?php

namespace Tests\Feature;

use App\Livewire\FieldCommandApp;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireFieldCommandAppTest extends TestCase
{
    use RefreshDatabase;

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

        Livewire::test(FieldCommandApp::class)
            ->assertSet('activeTab', 'report')
            ->call('setTab', 'qr')
            ->assertSet('activeTab', 'qr')
            ->call('setTab', 'report')
            ->call('incrementTrade', 'elec')
            ->call('recordCommute', 'in')
            ->assertSet('last_scan_status', '출근 완료')
            ->call('addEquipment')
            ->assertHasNoErrors();
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
}
