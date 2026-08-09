<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\SiteAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 프로젝트 등록이 설치공사에 필요한 것을 다 담는가.
 *
 * 여기 담기는 값 중 둘은 그냥 참고 자료가 아니다 — prevailing_wage_required 와
 * certified_payroll_required 는 급여 마감에서 WH-347 제출 대상을 고르는 근거다.
 * 등록 화면에서 빠지면 대상인 줄 모르고 지나간다.
 */
class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_registration_stores_installation_project_metadata(): void
    {
        $company = Company::query()->create(['code' => 'DASOL PRISM', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $client = Company::query()->create(['code' => 'LGES', 'name' => 'LG Energy Solution', 'status' => 'active']);
        $upperContractor = Company::query()->create(['code' => 'SAMSUNG-EA', 'name' => 'Samsung E&A', 'status' => 'active']);

        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona Plant',
            'address' => 'Queen Creek, AZ',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);

        $this->actingAs(User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $res = app(SiteAdminService::class)->saveProject([
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
            'site_id' => $site->id,
            'state' => 'AZ',
            'jurisdiction' => 'Pinal County',
            'ntp_date' => '2026-07-01',
            'mobilization_date' => '2026-07-10',
            'planned_completion_date' => '2026-12-15',
            'contract_amount' => '1250000.50',
            'currency' => 'USD',
            'budget_labor_amount' => '550000',
            'budget_material_amount' => '180000',
            'budget_equipment_amount' => '220000',
            'budget_expense_amount' => '95000',
            'retainage_percent' => '10',
            'payment_terms' => 'Progress Billing, Net 30',
            'prevailing_wage_required' => true,
            'davis_bacon_required' => false,
            'union_status' => 'non_union_open_shop',
            'certified_payroll_required' => true,
            'ocip_ccip_status' => 'ocip',
            'bonding_required' => true,
            'osha_plan_status' => 'submitted',
            'lien_notice_required' => true,
            'preliminary_notice_due_on' => '2026-07-20',
            'per_diem_policy' => '$180/day lodging and meal allowance.',
        ]);

        $this->assertTrue($res['success']);

        $project = Project::query()->firstOrFail();

        // 코드를 비우면 현장·주·연도로 만든다 — 사람이 규칙을 외울 필요가 없다.
        $this->assertStringStartsWith('LGESAZ-AZ-', $project->project_code);
        $this->assertSame('LG Energy Solution Arizona Module Installation', $project->name);
        $this->assertSame('awarded', $project->project_stage);
        $this->assertSame($company->id, $project->company_id);
        $this->assertSame($upperContractor->id, $project->upper_contractor_company_id);
        $this->assertSame('Pinal County', $project->jurisdiction);
        $this->assertSame(1250000.50, (float) $project->contract_amount);
        $this->assertSame(550000.0, (float) $project->budget_labor_amount);
        $this->assertSame('Progress Billing, Net 30', $project->payment_terms);

        // 급여 마감이 읽는 값들.
        $this->assertTrue($project->prevailing_wage_required);
        $this->assertTrue($project->certified_payroll_required);
        $this->assertFalse($project->davis_bacon_required);
        $this->assertSame('non_union_open_shop', $project->union_status);
        $this->assertSame('ocip', $project->ocip_ccip_status);
        $this->assertSame('submitted', $project->osha_plan_status);
        $this->assertSame('2026-07-20', $project->preliminary_notice_due_on?->toDateString());
    }

    public function test_editing_a_project_keeps_plan_tables_that_the_form_does_not_show(): void
    {
        // milestone_plan 같은 표 항목은 아직 이 화면에서 못 고친다. 저장할 때
        // 지워지면 안 된다 — 안 보이는 데이터가 조용히 사라지는 것이 제일 나쁘다.
        $company = Company::query()->create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'AZ', 'name' => 'AZ',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'AZ-2026-001', 'name' => 'Keep',
            'construction_type' => 'equipment_setting',
            'milestone_plan' => ['Equipment inbound' => '2026-08-01'],
            'workforce_plan' => ['Foreman' => '2'],
        ]);

        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        app(SiteAdminService::class)->saveProject([
            'id' => $project->id,
            'name' => 'Keep (renamed)',
            'site_id' => $site->id,
            'project_code' => 'AZ-2026-001',
            'construction_type' => 'equipment_setting',
        ]);

        $project->refresh();
        $this->assertSame('Keep (renamed)', $project->name);
        $this->assertSame('2026-08-01', $project->milestone_plan['Equipment inbound']);
        $this->assertSame('2', $project->workforce_plan['Foreman']);
    }
}
