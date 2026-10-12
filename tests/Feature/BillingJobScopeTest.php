<?php

namespace Tests\Feature;

use App\Jobs\MatchDailyClaimEvidence;
use App\Models\ClaimEvidenceInboxItem;
use App\Models\Company;
use App\Models\MobileExpense;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Support\JobAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

/** The central JobDataScope must remain effective through legacy billing carrier roles and queued actors. */
class BillingJobScopeTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $company = Company::create(['code' => 'BILL-SCOPE', 'name' => 'Scoped company', 'status' => 'active']);
        $foreignCompany = Company::create(['code' => 'BILL-FOREIGN', 'name' => 'Other company', 'status' => 'active']);
        $sites = [];
        $contracts = [];
        foreach (['A', 'B', 'C', 'FOREIGN'] as $code) {
            $owner = $code === 'FOREIGN' ? $foreignCompany : $company;
            $site = Site::create(['company_id' => $owner->id, 'code' => 'BILL-'.$code, 'name' => $code, 'status' => 'active']);
            $sites[] = $site;
            $contracts[] = ProjectContract::create(['company_id' => $owner->id, 'site_id' => $site->id,
                'title' => 'Contract '.$code, 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 1000]);
        }

        return [$company, $sites, $contracts];
    }

    private function actor(Company $company, array $sites, string $scope = 'site'): User
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->forceFill(JobAccess::grant(['jobRole' => 'office', 'jobDuties' => ['accounting'],
            'companyId' => $company->id, 'scope' => $scope, 'siteIds' => array_map(fn ($site) => $site->id, $sites)]))->save();

        return $actor;
    }

    public function test_managed_admin_retains_multiple_assigned_sites_in_billing_reads_and_writes(): void
    {
        [$company, $sites, $contracts] = $this->fixtures();
        $actor = $this->actor($company, [$sites[0], $sites[1]]);
        $this->assertSame('admin', $actor->access_role);
        $this->actingAsPurchaseUser($actor);
        $billing = app(BillingAdminService::class);
        $this->assertTrue($billing->canManage());
        $this->assertEqualsCanonicalizing([$contracts[0]->id, $contracts[1]->id], array_column($billing->getBillingContracts()['rows'], 'id'));
        $this->assertEqualsCanonicalizing([(string) $contracts[0]->id, (string) $contracts[1]->id], array_column($billing->getBillingOptions()['contracts'], 'value'));
        $this->assertNotNull($billing->findAccessibleContract($contracts[1]->id));
        foreach ([$contracts[2], $contracts[3]] as $outside) {
            $this->assertNull($billing->findAccessibleContract($outside->id));
            $this->assertFalse($billing->getBillings($outside->id)['success']);
            $this->assertFalse($billing->saveBilling(['projectContractId' => $outside->id,
                'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31', 'thisPeriodAmount' => 100])['success']);
        }
        $this->assertDatabaseCount('pay_applications', 0);
    }

    public function test_company_scope_includes_its_office_contract_but_excludes_other_companies(): void
    {
        [$company, $sites, $contracts] = $this->fixtures();
        $office = ProjectContract::create(['company_id' => $company->id, 'site_id' => null,
            'title' => 'Company office contract', 'direction' => 'receivable', 'status' => 'active']);
        $actor = $this->actor($company, [], 'company');
        $this->actingAsPurchaseUser($actor);
        $billing = app(BillingAdminService::class);
        $this->assertEqualsCanonicalizing([$contracts[0]->id, $contracts[1]->id, $contracts[2]->id, $office->id],
            array_column($billing->getBillingContracts()['rows'], 'id'));
        $this->assertNull($billing->findAccessibleContract($contracts[3]->id));
    }

    public function test_incomplete_managed_scope_fails_closed_while_legacy_global_admin_remains_global(): void
    {
        [$company, $sites, $contracts] = $this->fixtures();
        $actor = $this->actor($company, [$sites[0]]);
        $actor->forceFill(['allowed_company_id' => null, 'job_site_ids' => [], 'allowed_site_id' => null, 'access_scope' => 'all_sites'])->save();
        $this->actingAsPurchaseUser($actor);
        $billing = app(BillingAdminService::class);
        $this->assertSame([], $billing->getBillingContracts()['rows']);
        $this->assertNull($billing->findAccessibleContract($contracts[0]->id));
        foreach (['super_admin', 'admin'] as $role) {
            $this->actingAsPurchaseUser(User::factory()->create(['access_role' => $role,
                'access_scope' => 'all_sites', 'account_status' => 'active', 'job_role' => null]));
            $this->assertEqualsCanonicalizing(array_map(fn ($contract) => $contract->id, $contracts),
                array_column($billing->getBillingContracts()['rows'], 'id'));
        }
    }

    public function test_queued_source_reads_use_recorded_manager_scope_and_restore_previous_actor(): void
    {
        [$company, $sites, $contracts] = $this->fixtures();
        $actor = $this->actor($company, [$sites[0]]);
        $owner = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($owner);
        $token = 'scope-test-token';
        $contracts[0]->update(['payload' => ['dailyEvidenceAutomation' => ['enabled' => true,
            'actorId' => $actor->id, 'state' => 'queued', 'runToken' => $token, 'queuedAt' => now()->toIso8601String()]]]);
        foreach ([0, 3] as $index) {
            $expense = MobileExpense::withoutEvents(fn () => MobileExpense::create([
                'company_id' => $contracts[$index]->company_id, 'site_id' => $sites[$index]->id,
                'payment_type' => 'corporate', 'category' => 'materials', 'description' => $index === 0 ? 'Own receipt' : 'Foreign receipt',
                'expense_date' => '2026-10-01', 'amount' => 100, 'status' => 'pending',
            ]));
            // Even an existing incorrect source association must not bypass present source permissions.
            ClaimEvidenceInboxItem::create(['project_contract_id' => $contracts[0]->id,
                'source_type' => 'expense', 'source_id' => $expense->id, 'status' => 'review', 'line_ids' => []]);
        }
        $actualInbox = app(ClaimEvidenceInboxService::class);
        $inbox = Mockery::mock(ClaimEvidenceInboxService::class);
        $inbox->shouldReceive('match')->once()->with($contracts[0]->id)->andReturnUsing(function () use ($actor, $contracts, $actualInbox): array {
            $this->assertSame($actor->id, Auth::id());
            $this->assertNull(app(BillingAdminService::class)->findAccessibleContract($contracts[3]->id));
            $result = $actualInbox->getInbox($contracts[0]->id);
            $this->assertTrue($result['success']);
            $this->assertSame(['Own receipt'], array_column($result['rows'], 'title'));

            return ['success' => true];
        });
        (new MatchDailyClaimEvidence($contracts[0]->id, $actor->id, $token))->handle($inbox);
        $this->assertSame($owner->id, Auth::id());
        $this->assertSame('done', $contracts[0]->fresh()->payload['dailyEvidenceAutomation']['state']);
        $this->assertDatabaseCount('pay_applications', 0);
    }
}
