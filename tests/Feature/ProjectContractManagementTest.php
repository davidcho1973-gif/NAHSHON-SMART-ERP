<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectContracts\Pages\ManageProjectContractDocuments;
use App\Filament\Resources\ProjectContracts\Pages\ManageProjectContracts;
use App\Filament\Resources\ProjectContracts\ProjectContractResource;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectContractManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_project_contract_from_filament(): void
    {
        [$company, $counterparty, $site, $project] = $this->projectFixture();
        $this->actingAs($this->user('super_admin'));

        Livewire::test(ManageProjectContracts::class)
            ->mountAction('create')
            ->set('mountedActions.0.data', [
                'company_id' => $company->id,
                'counterparty_company_id' => $counterparty->id,
                'site_id' => $site->id,
                'project_id' => $project->id,
                'contract_number' => 'GC-PO-2026-1001',
                'title' => 'Arizona Module Installation Prime Contract',
                'direction' => 'receivable',
                'counterparty_role' => 'general_contractor',
                'contract_type' => 'prime_contract',
                'status' => 'active',
                'risk_level' => 'medium',
                'original_amount' => '1250000.00',
                'approved_change_amount' => '50000.00',
                'currency' => 'usd',
                'retainage_percent' => '10',
                'payment_terms' => 'Progress Billing, Net 30',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-01-31',
                'renewal_notice_days' => 60,
                'insurance_required' => true,
                'bond_required' => true,
                'scope_of_work' => 'Module setting and commissioning support.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $contract = ProjectContract::query()->firstOrFail();

        $this->assertStringStartsWith('CTR-'.now()->format('Y').'-', $contract->internal_reference);
        $this->assertSame($project->id, $contract->project_id);
        $this->assertSame($counterparty->id, $contract->counterparty_company_id);
        $this->assertSame('1300000.00', $contract->current_amount);
        $this->assertSame('USD', $contract->currency);
    }

    public function test_private_contract_document_can_be_reviewed_and_downloaded_by_authorized_user(): void
    {
        Storage::fake('local');
        [, , , $project] = $this->projectFixture();
        $admin = $this->user('admin');
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
        $this->assertSame('PrimeContract-v1.pdf', $document->original_file_name);

        Livewire::test(ManageProjectContractDocuments::class, ['record' => $contract->id])
            ->assertOk()
            ->mountTableAction('approve', $document)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $document->refresh();
        $this->assertSame('approved', $document->status);
        $this->assertSame($admin->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);

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

        $this->assertSame([$allowedContract->id], ProjectContractResource::getEloquentQuery()->pluck('id')->all());

        $this->get(route('project-contract-document.download', $blockedDocument))->assertForbidden();

        $worker = $this->user('worker', 'self');
        $this->actingAs($worker);
        $this->get(route('project-contract-document.download', $blockedDocument))->assertForbidden();
    }

    public function test_deleting_document_removes_private_file_and_payroll_is_read_only(): void
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
        Storage::disk('local')->assertMissing('project-contract-documents/to-delete.pdf');

        $this->actingAs($this->user('payroll'));
        $this->assertTrue(ProjectContractResource::canViewAny());
        $this->assertFalse(ProjectContractResource::canCreate());
        $this->assertFalse(ProjectContractResource::canDelete(null));
    }

    public function test_site_manager_write_scope_is_enforced_server_side(): void
    {
        [$company, , $site, $project] = $this->projectFixture();
        $otherCompany = Company::query()->create(['code' => 'OTHER-CO', 'name' => 'Other Company', 'status' => 'active']);
        $otherSite = Site::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'OTHER-SITE',
            'name' => 'Other Site',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'company_id' => $otherCompany->id,
            'site_id' => $otherSite->id,
            'project_code' => 'OTHER-2026-001',
            'name' => 'Other Project',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'awarded',
        ]);

        $this->actingAs($this->user('site_manager', 'site', allowedSiteId: $site->id));

        $scoped = ProjectContractResource::enforceWritableScope([
            'company_id' => $otherCompany->id,
            'site_id' => $otherSite->id,
            'project_id' => $project->id,
        ]);

        $this->assertSame($company->id, $scoped['company_id']);
        $this->assertSame($site->id, $scoped['site_id']);

        $this->expectException(ValidationException::class);
        ProjectContractResource::enforceWritableScope([
            'company_id' => $otherCompany->id,
            'site_id' => $otherSite->id,
            'project_id' => $otherProject->id,
        ]);
    }

    /** @return array{Company, Company, Site, Project} */
    private function projectFixture(): array
    {
        $company = Company::query()->create(['code' => 'NAHSHON', 'name' => 'NAHSHON MEP', 'status' => 'active']);
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
            'counterparty_company_id' => $project->upper_contractor_company_id ?: $project->end_client_company_id,
            'site_id' => $project->site_id,
            'project_id' => $project->id,
            'title' => $title,
            'direction' => 'receivable',
            'counterparty_role' => 'general_contractor',
            'contract_type' => 'prime_contract',
            'status' => 'active',
        ]);
    }

    private function user(string $role, string $scope = 'all_sites', ?int $allowedSiteId = null): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => $scope,
            'allowed_site_id' => $allowedSiteId,
            'account_status' => 'active',
        ]);
    }
}
