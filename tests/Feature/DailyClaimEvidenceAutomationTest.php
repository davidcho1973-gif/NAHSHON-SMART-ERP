<?php

namespace Tests\Feature;

use App\Jobs\MatchDailyClaimEvidence;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Finance\ClaimEvidenceAutomation;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Support\JobEndpointPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyClaimEvidenceAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): ProjectContract
    {
        $company = Company::create(['code' => 'DAILY', 'name' => 'Daily evidence', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'DAILY', 'name' => 'Site', 'status' => 'active']);
        return ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id,
            'direction' => 'receivable', 'title' => 'Daily work', 'status' => 'active',
            'payload' => ['claim_profile' => ['version' => 3], 'existing' => 'preserve']]);
    }

    public function test_queue_is_durable_idempotent_and_checks_revoked_permission_before_processing(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($actor);
        $contract = $this->contract();
        $service = app(ClaimEvidenceAutomation::class);
        $this->assertTrue($service->enqueue($contract->id)['success']);
        $this->assertTrue($service->enqueue($contract->id)['success']);
        Queue::assertPushed(MatchDailyClaimEvidence::class, 1);
        $state = $contract->fresh()->payload['dailyEvidenceAutomation'];
        Queue::assertPushed(MatchDailyClaimEvidence::class, fn ($job) => $job->connection === 'document-analysis' && $job->queue === 'claim-evidence');
        $this->assertSame('preserve', $contract->fresh()->payload['existing']);
        $this->assertSame(3, $contract->fresh()->payload['claim_profile']['version']);
        $this->assertFalse(ClaimEvidenceAutomation::update($contract->id, 'stale-token', ['state' => 'done']));
        $actor->update(['account_status' => 'suspended']);
        $inbox = $this->mock(ClaimEvidenceInboxService::class);
        $inbox->shouldNotReceive('match');
        (new MatchDailyClaimEvidence($contract->id, $actor->id, $state['runToken']))->handle($inbox);
        $this->assertSame('failed', $contract->fresh()->payload['dailyEvidenceAutomation']['state']);
        $this->assertDatabaseCount('pay_applications', 0);
        $this->assertDatabaseCount('claim_work_records', 0);
    }

    public function test_worker_and_outside_site_cannot_start_automation_or_read_source(): void
    {
        Queue::fake();
        $contract = $this->contract();
        $worker = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);
        $this->actingAs($worker);
        $this->assertFalse(app(ClaimEvidenceAutomation::class)->enqueue($contract->id)['success']);
        $this->get('/billing-export/contracts/'.$contract->id.'/daily-evidence/expense/1')->assertForbidden();
        $manager = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => null, 'account_status' => 'active']);
        $this->actingAs($manager);
        $this->assertFalse(app(ClaimEvidenceAutomation::class)->enqueue($contract->id)['success']);
        Queue::assertNothingPushed();
    }

    public function test_scheduler_only_runs_enabled_contracts_and_keeps_existing_actor_scope(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($actor);
        $contract = $this->contract();
        ContractBoqLine::create(['project_contract_id' => $contract->id, 'line_no' => '1', 'description' => 'Pipe',
            'unit' => 'LF', 'contract_qty' => 1, 'unit_price' => 1, 'status' => 'draft', 'recognition_basis' => 'quantity']);
        $this->artisan('claims:sync-evidence')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('claims:sync-evidence', ['--user' => $actor->id, '--contract' => $contract->id])->assertSuccessful();
        Queue::assertPushed(MatchDailyClaimEvidence::class, 1);
        $this->artisan('claims:sync-evidence')->assertSuccessful();
        Queue::assertPushed(MatchDailyClaimEvidence::class, 1);
        $this->assertSame(['contracts', 'edit'], JobEndpointPolicy::legacy('api_matchClaimEvidence'));
        $this->assertSame(['contracts', 'view'], JobEndpointPolicy::legacy('api_getClaimEvidenceInbox'));
    }
}
