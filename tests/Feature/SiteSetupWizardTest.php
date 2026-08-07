<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $unauthorizedUser;
    private Company $company;
    private Site $newSite;
    private Employee $managerCandidate;
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'NAHSHON',
            'name' => 'NAHSHON MEP',
            'status' => 'active',
        ]);

        // A new site that has not been set up yet (setup_completed_at is null)
        $this->newSite = Site::create([
            'company_id' => $this->company->id,
            'code' => 'LGES-AZ2',
            'name' => 'LGES Arizona Site 2',
            'status' => 'active',
            'setup_completed_at' => null,
        ]);

        $this->managerCandidate = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'John',
            'last_name' => 'Manager',
            'email' => 'john.manager@example.com',
            'role' => 'Project Manager',
            'employment_status' => 'active',
        ]);

        $this->worker = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Bill',
            'last_name' => 'Worker',
            'email' => 'bill.worker@example.com',
            'role' => 'Pipefitter',
            'employment_status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
            'access_role' => 'admin',
            'account_status' => 'active',
        ]);

        $this->unauthorizedUser = User::factory()->create([
            'email' => 'worker@example.com',
            'access_role' => 'worker',
            'account_status' => 'active',
        ]);
    }

    public function test_it_loads_wizard_assets_successfully(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/smart-company-api/api_getSetupWizardAssets', [
            'args' => [],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonFragment(['name' => 'John Manager']);
        $response->assertJsonFragment(['name' => 'Bill Worker']);
    }

    public function test_it_executes_site_setup_wizard_successfully(): void
    {
        // Mock equipment
        $equipmentId = null;
        if (Schema::hasTable('equipments')) {
            $equipmentId = DB::table('equipments')->insertGetId([
                'company_id' => $this->company->id,
                'equipment_code' => 'EQ-WIZ-999',
                'equipment_type' => 'Forklift',
                'model' => 'FL-3000',
                'status' => '대기중',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $postData = [
            'manager_id' => $this->managerCandidate->id,
            'load_default_wbs' => true,
            'equipment_ids' => $equipmentId ? [$equipmentId] : [],
            'employee_ids' => [$this->worker->id],
        ];

        $response = $this->actingAs($this->admin)->postJson('/smart-company-api/api_setupSite', [
            'args' => [$this->newSite->code, $postData],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Assert setup status updated
        $this->newSite->refresh();
        $this->assertNotNull($this->newSite->setup_completed_at);
        $this->assertEquals($this->managerCandidate->id, $this->newSite->manager_employee_id);

        // Assert manager and worker assigned to the new site
        $this->managerCandidate->refresh();
        $this->worker->refresh();
        $this->assertEquals($this->newSite->id, $this->managerCandidate->site_id);
        $this->assertEquals($this->newSite->id, $this->worker->site_id);

        // Assert default WBS items created
        if (Schema::hasTable('wbs_items')) {
            $this->assertDatabaseHas('wbs_items', [
                'site_id' => $this->newSite->id,
                'level' => 'stage',
                'name' => 'STAGE 1. Winder (권취 공정)',
            ]);
        }

        // Assert equipment assigned
        if ($equipmentId) {
            $this->assertDatabaseHas('equipments', [
                'id' => $equipmentId,
                'site_id' => $this->newSite->id,
            ]);
        }
    }

    public function test_it_denies_setup_for_unauthorized_users(): void
    {
        $postData = [
            'manager_id' => $this->managerCandidate->id,
            'load_default_wbs' => false,
            'equipment_ids' => [],
            'employee_ids' => [],
        ];

        $response = $this->actingAs($this->unauthorizedUser)->postJson('/smart-company-api/api_setupSite', [
            'args' => [$this->newSite->code, $postData],
            'siteId' => 'ALL',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'error' => '현장 초기 셋업 권한이 없습니다.']);
    }
}
