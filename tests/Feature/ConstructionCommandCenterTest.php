<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyCrewReport;
use App\Models\DocumentActionItem;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\ExpensePreApproval;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\Team;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WbsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConstructionCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00', 'America/Phoenix'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_center_aggregates_live_erp_records_without_demo_fallbacks(): void
    {
        [$company, $site, $team, $admin] = $this->siteFixture('PHX-01');
        $project = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_code' => 'PHX-REAL-2026-001',
            'name' => 'Phoenix Real Project',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'in_progress',
            'planned_completion_date' => '2026-07-20',
        ]);
        $wbs = WbsItem::query()->create([
            'project_id' => $project->id,
            'project_code' => $project->project_code,
            'site_id' => $site->id,
            'company_id' => $company->id,
            'level' => WbsItem::LEVEL_SUBTASK,
            'wbs_code' => 'PHX-REAL-W-1',
            'name' => '실제 배관 설치',
            'status' => WbsItem::STATUS_IN_PROGRESS,
            'progress' => 35,
            'manhours' => 40,
            'crew_size' => 3,
            'is_critical' => true,
            'planned_start' => '2026-07-20',
            'planned_end' => '2026-07-25',
        ]);
        $safety = SafetyWorkItem::query()->create([
            'work_code' => 'WRK-PHX-REAL',
            'company_id' => $company->id,
            'site_id' => $site->id,
            'team_id' => $team->id,
            'work_date' => '2026-07-26',
            'wbs_code' => $wbs->wbs_code,
            'title' => '배관 설치 TBM',
            'crew' => 3,
            'plan_status' => '승인완료',
            'tbm_status' => '대기',
            'close_status' => '시작전',
            'progress_status' => '미분석',
        ]);
        $safety->issues()->create([
            'type' => '위험상황',
            'body' => '리프트 작업구역 통제 필요',
            'owner' => 'Safety Lead',
            'status' => '조치중',
        ]);

        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'team_id' => $team->id,
            'employee_number' => 'EMP-PHX-001',
            'name' => 'Live Worker',
            'employment_status' => 'active',
        ]);
        AttendanceLog::query()->create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'team_id' => $team->id,
            'attendance_date' => '2026-07-26',
            'event_type' => 'clock_in',
            'event_at' => '2026-07-26 07:00:00',
            'source' => 'team_qr',
            'status' => 'approved',
        ]);
        DailyCrewReport::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'team_id' => $team->id,
            'work_date' => '2026-07-26',
            'scanned_headcount' => 1,
            'external_headcount' => 4,
            'final_headcount' => 5,
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        MobileExpense::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'employee_id' => $employee->id,
            'payment_type' => 'company_card',
            'category' => '5100 Job Materials',
            'description' => 'Actual conduit purchase',
            'amount' => 850.25,
            'expense_date' => '2026-07-26',
            'status' => 'pending',
        ]);
        ExpensePreApproval::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'employee_id' => $employee->id,
            'title' => 'Actual lift extension',
            'description' => 'Extend the active lift rental for ceiling rough-in.',
            'justification' => 'The current WBS activity is still in progress.',
            'estimated_amount' => 1200,
            'planned_date' => '2026-07-27',
            'payment_method' => 'company_card',
            'status' => 'pending',
        ]);
        Equipment::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'equipment_code' => 'EQ-PHX-REAL',
            'equipment_type' => 'Scissor Lift',
            'model' => 'GS-1930',
            'acquisition_type' => 'rental',
            'rent_end' => '2026-07-25',
            'status' => '사용중',
        ]);

        $document = $this->document($admin, $company, $site, $project);
        DocumentActionItem::query()->create([
            'intelligent_document_id' => $document->id,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'action_type' => 'notice',
            'related_module' => 'CONTRACT',
            'severity' => 'critical',
            'status' => 'open',
            'title' => 'Submit actual delay notice',
            'due_at' => now()->subDay(),
        ]);
        UnifiedAlert::query()->create([
            'alert_code' => 'ALT-PHX-REAL',
            'fingerprint' => 'command-center-real-alert',
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'source_module' => 'DOC',
            'event_type' => 'deadline',
            'severity' => 'critical',
            'status' => 'unresolved',
            'title' => 'Actual contract deadline',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson('/smart-company-api/api_getConstructionCommandCenter', [
            'args' => [$site->code],
            'siteId' => $site->code,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('isLive', true)
            ->assertJsonPath('scope.selectedSiteCode', 'PHX-01')
            ->assertJsonPath('workforce.checkedIn', 1)
            ->assertJsonPath('workforce.externalHeadcount', 4)
            ->assertJsonPath('workforce.finalHeadcount', 5)
            ->assertJsonPath('finance.pendingAmount', 2050.25)
            ->assertJsonPath('equipment.rentalOverdue', 1)
            ->assertJsonPath('documents.overdueActions', 1)
            ->assertJsonPath('projects.0.code', 'PHX-REAL-2026-001')
            ->assertJsonPath('projects.0.progress', 35)
            ->assertJsonPath('projects.0.risk', 'critical')
            ->assertJsonPath('todayWork.0.tbmGated', true)
            ->assertJsonPath('alerts.items.0.id', 'ALT-PHX-REAL')
            ->assertJsonFragment(['source' => 'mobile_expenses + expense_pre_approvals'])
            ->assertJsonMissing(['code' => 'HFF-02'])
            ->assertJsonMissing(['title' => 'Approve rental extension']);
    }

    public function test_command_center_applies_site_scope_to_every_visible_module(): void
    {
        [$company, $allowedSite, $allowedTeam] = $this->siteFixture('ALLOW-01');
        [, $blockedSite, $blockedTeam] = $this->siteFixture('BLOCK-02', $company);
        $manager = User::factory()->create([
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $allowedSite->id,
            'account_status' => 'active',
        ]);

        foreach ([[$allowedSite, $allowedTeam, 'ALLOWED-PROJECT'], [$blockedSite, $blockedTeam, 'BLOCKED-PROJECT']] as [$site, $team, $code]) {
            Project::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'project_code' => $code,
                'name' => $code,
                'construction_type' => 'equipment_setting',
                'project_stage' => 'in_progress',
            ]);
            Equipment::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'team_id' => $team->id,
                'equipment_code' => 'EQ-'.$code,
                'equipment_type' => 'Lift',
                'model' => $code,
                'status' => '사용중',
            ]);
        }

        $response = $this->actingAs($manager)->postJson('/smart-company-api/api_getConstructionCommandCenter', [
            'siteId' => 'ALL',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('scope.siteCount', 1)
            ->assertJsonPath('scope.siteIds.0', $allowedSite->id)
            ->assertJsonPath('projects.0.code', 'ALLOWED-PROJECT')
            ->assertJsonPath('equipment.records', 1)
            ->assertJsonMissing(['code' => 'BLOCKED-PROJECT']);

        $this->actingAs($manager)->postJson('/smart-company-api/api_getConstructionCommandCenter', [
            'siteId' => 'BLOCK-02',
        ])->assertJsonPath('success', false);
    }

    public function test_empty_database_returns_truthful_zero_state_instead_of_sample_data(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getConstructionCommandCenter', ['siteId' => 'ALL'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('isLive', true)
            ->assertJsonPath('scope.siteCount', 0)
            ->assertJsonPath('health.decisionQueue', 0)
            ->assertJsonPath('health.pendingCost', 0)
            ->assertJsonPath('workforce.checkedIn', 0)
            ->assertJsonPath('equipment.total', 0)
            ->assertJsonPath('dataQuality.hasOperationalData', false)
            ->assertJsonMissing(['code' => 'LGES-AZ'])
            ->assertJsonMissing(['amount' => 18400]);
    }

    /** @return array{Company, Site, Team, User} */
    private function siteFixture(string $code, ?Company $company = null): array
    {
        $company ??= Company::query()->create([
            'code' => 'NAHSHON-'.$code,
            'name' => 'NAHSHON '.$code,
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code.' Site',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $team = Team::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code.'-TEAM',
            'name' => $code.' Team',
            'planned_headcount' => 6,
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        return [$company, $site, $team, $admin];
    }

    private function document(User $user, Company $company, Site $site, Project $project): IntelligentDocument
    {
        $uuid = (string) Str::uuid();

        return IntelligentDocument::query()->create([
            'uuid' => $uuid,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_id' => $project->id,
            'uploaded_by' => $user->id,
            'disk' => 'local',
            'file_path' => 'tests/'.$uuid.'/notice.txt',
            'original_file_name' => 'actual-notice.txt',
            'stored_file_name' => 'actual-notice.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'file_size' => 10,
            'sha256' => hash('sha256', $uuid),
            'title' => 'Actual Notice',
            'status' => 'received',
            'ai_status' => 'ready',
            'received_at' => now(),
        ]);
    }
}
