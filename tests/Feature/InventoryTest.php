<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'NAHSHON', 'name' => 'NAHSHON MEP', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona', 'status' => 'active']);
        $this->user = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        // Clear the demo-seed owned assets so counts are deterministic.
        Equipment::query()->delete();
        $this->actingAs($this->user);
    }

    public function test_dashboard_aggregates_owned_and_rented_with_real_recent(): void
    {
        Equipment::create([
            'equipment_code' => 'OWN-T1', 'site_id' => $this->site->id, 'equipment_type' => 'Welding Machine (용접기)',
            'model' => 'Miller XMT 350', 'acquisition_type' => '소유', 'asset_value' => 5000, 'status' => '사용중',
        ]);
        Equipment::create([
            'equipment_code' => 'RENT-T1', 'site_id' => $this->site->id, 'equipment_type' => 'Storage Container',
            'model' => "40' CONTAINER", 'vendor' => 'WillScot', 'acquisition_type' => '임대', 'status' => '대기중',
        ]);

        $d = app(InventoryService::class)->dashboard('ALL');

        $this->assertSame(2, $d['totals']['count']);
        $this->assertSame(5000.0, $d['totals']['value']);
        $this->assertSame(1, $d['totals']['owned']);
        $this->assertSame(1, $d['totals']['rented']);
        $this->assertSame(1, $d['totals']['inUse']);
        $this->assertSame(1, $d['totals']['inStorage']);

        // recent must be REAL equipment (the old bug showed mock smart_records with undefined fields).
        $codes = array_column($d['recent'], 'assetId');
        $this->assertContains('RENT-T1', $codes);
        $this->assertContains('OWN-T1', $codes);

        // matrix cell for the rented container at the site.
        $this->assertSame(1, $d['matrix']['cells']['Storage Container']['LGES-AZ']);
    }

    public function test_dashboard_counts_only_inspections_within_30_days(): void
    {
        Equipment::create([
            'equipment_code' => 'INS-SOON', 'equipment_type' => 'Power Tool (전동공구)', 'model' => 'Drill',
            'acquisition_type' => '소유', 'status' => '대기중', 'inspection_due_on' => now()->addDays(10)->toDateString(),
        ]);
        Equipment::create([
            'equipment_code' => 'INS-LATER', 'equipment_type' => 'Power Tool (전동공구)', 'model' => 'Saw',
            'acquisition_type' => '소유', 'status' => '대기중', 'inspection_due_on' => now()->addDays(60)->toDateString(),
        ]);

        $d = app(InventoryService::class)->dashboard('ALL');

        $this->assertSame(1, $d['totals']['inspectionDue']);
        $this->assertCount(1, $d['upcomingInspections']);
        $this->assertSame('INS-SOON', $d['upcomingInspections'][0]['assetId']);
        $this->assertSame(10, $d['upcomingInspections'][0]['dDay']);
    }

    public function test_warehouse_assets_group_under_changgo_location(): void
    {
        Equipment::create([
            'equipment_code' => 'WH-1', 'site_id' => null, 'equipment_type' => 'Hand Tool (수공구)',
            'model' => 'Wrench Set', 'acquisition_type' => '소유', 'status' => '대기중',
        ]);

        $d = app(InventoryService::class)->dashboard('ALL');

        $this->assertContains('창고', $d['matrix']['sites']);
        $this->assertSame(1, $d['matrix']['cells']['Hand Tool (수공구)']['창고']);
    }

    public function test_api_inventory_dashboard_endpoint_returns_real_data(): void
    {
        Equipment::create([
            'equipment_code' => 'API-T1', 'site_id' => $this->site->id, 'equipment_type' => 'Generator & Power (발전기/동력원)',
            'model' => 'CAT XQ60', 'acquisition_type' => '소유', 'asset_value' => 31000, 'status' => '사용중',
        ]);

        $res = $this->postJson('/smart-company-api/api_getInventoryDashboard', ['args' => [], 'siteId' => 'ALL']);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('totals.value', 31000);
    }
}
