<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Finance\ClaimWorklistService;
use App\Support\JobEndpointPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClaimWorklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_site_contracts_still_appear_and_month_end_deadlines_are_clamped(): void
    {
        Carbon::setTestNow('2026-02-10 12:00:00');
        try {
            $company = Company::create(['code' => 'CW', 'name' => 'Claim work', 'status' => 'active']);
            $site = Site::create(['code' => 'CW1', 'name' => 'Completed field work', 'company_id' => $company->id, 'status' => 'closed']);
            $this->actingAs(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active', 'access_scope' => 'all_sites']));
            $contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'direction' => 'receivable',
                'title' => 'Closeout still due', 'status' => 'active', 'currency' => 'USD', 'current_amount' => 1000,
                'payload' => ['claim_profile' => ['version' => 1, 'confirmed_at' => now()->toIso8601String(), 'confirmed_by' => auth()->id(),
                    'basis_note' => 'Signed agreement', 'stored_materials' => false, 'submission_day' => 31, 'preparation_days' => 7, 'requirements' => []]]]);
            $result = app(ClaimWorklistService::class)->get();
            $this->assertTrue($result['success']);
            $row = collect($result['rows'])->firstWhere('id', $contract->id);
            $this->assertNotNull($row);
            $this->assertSame('2026-02-28', $row['submissionDue']);
            $this->assertSame('2026-02-21', $row['preparationOn']);
            $this->assertTrue($row['profileConfirmed']);
            $this->assertSame('확인 실적 · 첫 회차 준비', $row['nextAction']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_submission_approval_and_exports_require_distinct_permissions(): void
    {
        $this->assertSame(['contracts', 'edit'], JobEndpointPolicy::legacy('api_transitionClaimSubmission', [['status' => 'sent']]));
        $this->assertSame(['contracts', 'approve'], JobEndpointPolicy::legacy('api_transitionClaimSubmission', [['status' => 'accepted']]));
        $this->assertSame(['contracts', 'approve'], JobEndpointPolicy::legacy('api_transitionClaimSubmission', [['status' => 'changes_requested']]));
        $this->assertSame(['contracts', 'export'], JobEndpointPolicy::legacy('api_prepareClaimSubmission'));
    }
}
