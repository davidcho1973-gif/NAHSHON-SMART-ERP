<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\ContractAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 원청 계약과 계약 서류.
 *
 * 계약서에는 금액과 원청 도면이 들어 있다. 목록은 담당 범위대로 잘라 보여 주는데,
 * 서류 내려받기는 SPA 를 거치지 않고 파일 라우트로 바로 들어온다 — 링크만 알면
 * 남의 현장 계약서를 받아 갈 수 있으면 안 되므로 그 경로도 같은 범위로 막는다.
 */
class ProjectContractManagementTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ContractAdminService
    {
        return app(ContractAdminService::class);
    }

    public function test_admin_can_create_a_project_contract(): void
    {
        [$company, $counterparty, $site, $project] = $this->projectFixture();
        $this->actingAs($this->user('super_admin'));

        $res = $this->svc()->save([
            'companyId' => $company->id,
            'counterpartyId' => $counterparty->id,
            'siteId' => $site->id,
            'projectId' => $project->id,
            'contractNumber' => 'GC-PO-2026-1001',
            'title' => 'Arizona Module Installation Prime Contract',
            'direction' => 'receivable',
            'counterpartyRole' => 'general_contractor',
            'contractType' => 'prime_contract',
            'status' => 'active',
            'riskLevel' => 'medium',
            'originalAmount' => '1250000.00',
            'approvedChangeAmount' => '50000.00',
            'currency' => 'usd',
            'retainagePercent' => '10',
            'paymentTerms' => 'Progress Billing, Net 30',
            'startsOn' => '2026-07-01',
            'endsOn' => '2027-01-31',
            'renewalNoticeDays' => 60,
            'scopeOfWork' => 'Module setting and commissioning support.',
        ]);

        $this->assertTrue($res['success'], json_encode($res['errors'] ?? $res, JSON_UNESCAPED_UNICODE));

        $contract = ProjectContract::query()->firstOrFail();
        // 사내 번호는 자동으로 붙는다 — 원청 번호와 별개로 우리 쪽에서 부르는 이름이다.
        $this->assertStringStartsWith('CTR-'.now()->format('Y').'-', $contract->internal_reference);
        $this->assertSame($project->id, $contract->project_id);
        $this->assertSame($counterparty->id, $contract->counterparty_company_id);
        // 현재 금액 = 원계약 + 승인된 변경분. 사람이 더하게 하지 않는다.
        $this->assertSame(1300000.0, (float) $contract->current_amount);
        $this->assertSame('USD', $contract->currency);
    }

    public function test_authorized_user_can_download_a_confidential_contract_document(): void
    {
        Storage::fake('local');
        [$company, , , $project] = $this->projectFixture();
        $admin = $this->user('admin');
        session(['current_company_id' => $company->id]);
        $this->actingAs($admin);

        $contract = $this->contractFor($project);
        $path = 'project-contract-documents/executed-contract.pdf';
        Storage::disk('local')->put($path, '%PDF private contract');

        $document = $contract->documents()->create([
            'document_type' => 'executed_contract',
            'title' => 'Executed Prime Contract',
            'version' => '1.0',
            'status' => 'under_review',
            'is_current' => true,
            'is_confidential' => true,
            'disk' => 'local',
            'file_path' => $path,
            'original_file_name' => 'PrimeContract-v1.pdf',
            'uploaded_by' => $admin->id,
        ]);

        $this->assertSame(strlen('%PDF private contract'), $document->file_size);

        $this->get(route('project-contract-document.download', $document))
            ->assertOk()
            ->assertDownload('PrimeContract-v1.pdf');
    }

    public function test_contract_download_and_lists_respect_site_scope(): void
    {
        Storage::fake('local');
        [$company, $counterparty, $site, $project] = $this->projectFixture();
        $otherSite = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'OTHER-SITE',
            'name' => 'Other Site',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $otherSite->id,
            'project_code' => 'OTHER-AZ-2026-001',
            'name' => 'Other Project',
            'construction_type' => 'equipment_setting',
            'end_client_company_id' => $counterparty->id,
            'project_stage' => 'awarded',
        ]);

        $allowedContract = $this->contractFor($project);
        $blockedContract = $this->contractFor($otherProject, 'Blocked Contract');
        Storage::disk('local')->put('project-contract-documents/blocked.pdf', '%PDF blocked');
        $blockedDocument = $blockedContract->documents()->create([
            'document_type' => 'executed_contract',
            'title' => 'Blocked',
            'status' => 'approved',
            'disk' => 'local',
            'file_path' => 'project-contract-documents/blocked.pdf',
            'original_file_name' => 'blocked.pdf',
        ]);

        $siteManager = $this->user('site_manager', 'site', allowedSiteId: $site->id);
        $this->actingAs($siteManager);

        $ids = collect($this->svc()->list()['rows'])->pluck('id')->all();
        $this->assertSame([$allowedContract->id], $ids);

        // 목록에서 안 보이는 계약의 서류는 링크로도 못 받는다.
        $this->get(route('project-contract-document.download', $blockedDocument))->assertForbidden();

        $worker = $this->user('worker', 'self');
        $this->actingAs($worker);
        $this->get(route('project-contract-document.download', $blockedDocument))->assertForbidden();
    }

    public function test_deleting_document_removes_the_private_file(): void
    {
        Storage::fake('local');
        [, , , $project] = $this->projectFixture();
        $contract = $this->contractFor($project);
        Storage::disk('local')->put('project-contract-documents/to-delete.pdf', 'delete me');
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'title' => 'Temporary Document',
            'disk' => 'local',
            'file_path' => 'project-contract-documents/to-delete.pdf',
        ]);

        $document->delete();

        // 기록만 지우고 파일이 남으면 저장소에 계약서가 계속 떠돈다.
        Storage::disk('local')->assertMissing('project-contract-documents/to-delete.pdf');
    }

    public function test_payroll_can_read_contracts_but_not_change_them(): void
    {
        // 급여는 인증임금 조건을 확인하려고 계약을 본다 — 고칠 이유는 없다.
        [, , , $project] = $this->projectFixture();
        $this->contractFor($project);
        $this->actingAs($this->user('payroll'));

        $res = $this->svc()->list();

        $this->assertTrue($res['success']);
        $this->assertFalse($this->svc()->canManage());
        $this->assertNotContains('payroll', ContractAdminService::DELETE_ROLES);
    }

    /** @return array{Company, Company, Site, Project} */
    private function projectFixture(): array
    {
        $company = Company::query()->create(['code' => 'DASOL PRISM', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $counterparty = Company::query()->create(['code' => 'GC-USA', 'name' => 'GC USA', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'client_company_id' => $counterparty->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001',
            'name' => 'LGES Arizona Module Installation',
            'construction_type' => 'equipment_setting',
            'end_client_company_id' => $counterparty->id,
            'upper_contractor_company_id' => $counterparty->id,
            'project_stage' => 'awarded',
        ]);

        return [$company, $counterparty, $site, $project];
    }

    private function contractFor(Project $project, string $title = 'Prime Contract'): ProjectContract
    {
        return ProjectContract::query()->create([
            'company_id' => $project->company_id,
            'counterparty_company_id' => $project->end_client_company_id,
            'site_id' => $project->site_id,
            'project_id' => $project->id,
            'title' => $title,
            'direction' => 'receivable',
            'contract_type' => 'prime_contract',
            'status' => 'active',
        ]);
    }

    private function user(string $role, string $scope = 'all_sites', ?int $allowedSiteId = null): User
    {
        return User::factory()->create([
            'access_role' => $role,
            'access_scope' => $scope,
            'account_status' => 'active',
            'allowed_site_id' => $allowedSiteId,
        ]);
    }
}
