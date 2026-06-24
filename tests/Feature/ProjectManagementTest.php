<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ManageProjects;
use App\Models\Company;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_create_form_stores_installation_project_metadata(): void
    {
        $company = Company::query()->create([
            'code' => 'NAHSHON',
            'name' => 'NAHSHON MEP',
            'status' => 'active',
        ]);

        $client = Company::query()->create([
            'code' => 'LGES',
            'name' => 'LG Energy Solution',
            'status' => 'active',
        ]);

        $upperContractor = Company::query()->create([
            'code' => 'SAMSUNG-EA',
            'name' => 'Samsung E&A',
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona Plant',
            'address' => 'Queen Creek, AZ',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'name' => 'Project Admin',
            'email' => 'project-admin@example.com',
            'password' => bcrypt('password'),
            'access_role' => 'super_admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($user);

        Livewire::test(ManageProjects::class)
            ->mountAction('create')
            ->set('mountedActions.0.data', [
                'project_code' => '',
                'name' => 'LG Energy Solution Arizona Module Installation',
                'construction_type' => 'equipment_setting',
                'end_client_company_id' => $client->id,
                'project_stage' => 'awarded',
                'vendor_tier' => 'tier_2',
                'upper_contractor_company_id' => $upperContractor->id,
                'contract_type' => 'lump_sum',
                'po_number' => 'PO-2026-1001',
                'scope_of_work' => 'Module equipment setting and commissioning support.',
                'company_id' => $company->id,
                'site_id' => $site->id,
                'state' => 'AZ',
                'jurisdiction' => 'Pinal County',
                'ntp_date' => '2026-07-01',
                'mobilization_date' => '2026-07-10',
                'planned_completion_date' => '2026-12-15',
                'milestone_plan' => [
                    'Equipment inbound' => '2026-08-01',
                    'Setting complete' => '2026-10-15',
                    'Commissioning support' => '2026-11-01',
                ],
                'contract_amount' => '1250000.50',
                'currency' => 'USD',
                'budget_labor_amount' => '550000',
                'budget_material_amount' => '180000',
                'budget_equipment_amount' => '220000',
                'budget_expense_amount' => '95000',
                'retainage_percent' => '10',
                'payment_terms' => 'Progress Billing, Net 30',
                'cost_code_system' => 'Internal WBS',
                'wbs_code' => 'LGES-AZ-MOD-001',
                'prevailing_wage_required' => true,
                'davis_bacon_required' => false,
                'union_status' => 'non_union_open_shop',
                'certified_payroll_required' => true,
                'insurance_requirements' => [
                    'GL' => 'Required',
                    'Workers Comp' => 'Required',
                ],
                'ocip_ccip_status' => 'ocip',
                'bonding_required' => true,
                'osha_plan_status' => 'submitted',
                'lien_notice_required' => true,
                'preliminary_notice_due_on' => '2026-07-20',
                'workforce_plan' => [
                    'Foreman' => '2',
                    'Rigger' => '6',
                    'Helper' => '10',
                ],
                'korean_dispatch_plan' => [
                    'Supervisor' => 'E-2 / pending',
                ],
                'per_diem_policy' => '$180/day lodging and meal allowance.',
                'equipment_plan' => [
                    'Forklift' => 'Rental',
                    'Crane' => 'Vendor supplied',
                ],
                'subcontractor_plan' => [
                    'Rigging partner' => 'Heavy lift support',
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $project = Project::query()->firstOrFail();

        $this->assertStringStartsWith('LGES-AZ-', $project->project_code);
        $this->assertSame('LG Energy Solution Arizona Module Installation', $project->name);
        $this->assertSame('awarded', $project->project_stage);
        $this->assertTrue($project->prevailing_wage_required);
        $this->assertTrue($project->certified_payroll_required);
        $this->assertSame('2026-08-01', $project->milestone_plan['Equipment inbound']);
        $this->assertSame('Heavy lift support', $project->subcontractor_plan['Rigging partner']);
    }
}
