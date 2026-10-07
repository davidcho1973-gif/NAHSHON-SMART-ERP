<?php

namespace Tests\Feature;

use App\Models\AssistantCheck;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\ExpensePreApproval;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Assistant\AssistantCheckService;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistantCheckTemplatesTest extends TestCase
{
    use DatabaseMigrations;

    private Company $company;

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        $this->mock(WebPushSender::class)->shouldNotReceive('sendToUsers');
        config(['ai_assistant.checks_enabled' => true, 'org.ops.trade_report_due_hour' => 17]);
        $this->travelTo(Carbon::parse('2026-10-06T21:00:00Z'));
        $this->company = Company::create(['code' => 'CHECK-CO', 'name' => 'Checks Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'CHECK-SITE', 'name' => 'Site', 'status' => 'active', 'timezone' => 'America/New_York']);
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active', 'allowed_company_id' => $this->company->id]);
    }

    private function expense(array $extra = []): MobileExpense
    {
        return MobileExpense::create($extra + ['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'description' => 'Materials', 'amount' => '123.45', 'expense_date' => '2026-10-06', 'payment_type' => 'corporate', 'category' => 'Materials', 'status' => 'pending']);
    }

    private function preapproval(array $extra = []): ExpensePreApproval
    {
        return ExpensePreApproval::create($extra + ['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'title' => 'Travel', 'justification' => 'Site visit', 'estimated_amount' => '99.99', 'planned_date' => '2026-10-10', 'payment_method' => 'personal', 'status' => 'pending']);
    }

    private function employee(array $extra = []): Employee
    {
        return Employee::create($extra + ['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'Worker', 'role' => 'Piping', 'employment_type' => Employee::TYPE_DIRECT, 'employment_status' => 'active']);
    }

    private function clockIn(Employee $employee, array $extra = []): AttendanceLog
    {
        return AttendanceLog::create($extra + ['employee_id' => $employee->id, 'company_id' => $employee->company_id,
            'site_id' => $employee->site_id, 'team_id' => $employee->team_id, 'attendance_date' => '2026-10-06',
            'event_type' => 'clock_in', 'event_at' => Carbon::parse('2026-10-06T12:00:00Z'), 'source' => 'gate', 'status' => 'approved']);
    }

    private function report(string $trade, string $status = 'submitted', array $extra = []): DailyTradeReport
    {
        return DailyTradeReport::create($extra + ['site_id' => $this->site->id, 'work_date' => '2026-10-06', 'trade' => $trade, 'status' => $status]);
    }

    private function runCheck(string $kind, ?User $actor = null): AssistantCheck
    {
        $actor ??= $this->owner;
        $service = app(AssistantCheckService::class);
        $check = $service->save($actor, ['site_id' => $this->site->id, 'kind' => $kind, 'interval_hours' => 1]);
        $this->assertFalse($check->enabled);
        $service->activate($actor, $check->id, true, $check->approval_version);
        $this->assertGreaterThanOrEqual(1, $service->runDue());

        return $check->fresh();
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected current module or scope permission to reject access.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_pending_union_has_exact_statuses_separate_ids_and_no_money_projection(): void
    {
        $expense = $this->expense();
        $preapproval = $this->preapproval();
        foreach (['draft', 'approved', 'rejected', 'paid'] as $status) {
            $this->expense(['status' => $status]);
            $this->preapproval(['status' => $status]);
        }
        $result = $this->runCheck('pending_expense_approvals')->last_result;
        $this->assertSame(2, $result['count']);
        $this->assertEquals(['expense_preapprovals' => 1, 'expenses' => 1], $result['subcounts']);
        $this->assertSame(['expense_preapprovals:'.$preapproval->id, 'expenses:'.$expense->id], array_column($result['records'], 'id'));
        $this->assertSame(['2026-10-10', '2026-10-06'], array_column($result['records'], 'date'));
        foreach ($result['records'] as $record) {
            $this->assertArrayNotHasKey('amount', $record);
            $this->assertArrayNotHasKey('estimated_amount', $record);
        }
    }

    public function test_union_counts_every_authorized_row_before_the_shared_25_row_limit(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->preapproval();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->expense();
        }
        $result = $this->runCheck('pending_expense_approvals')->last_result;
        $this->assertSame(29, $result['count']);
        $this->assertEquals(['expense_preapprovals' => 26, 'expenses' => 3], $result['subcounts']);
        $this->assertCount(25, $result['records']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(range(1, 25), array_column($result['records'], 'record_id'));
    }

    public function test_union_and_procurement_exclude_other_sites_companies_and_inconsistent_company_rows(): void
    {
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'CHECK-OTHER', 'name' => 'Other', 'status' => 'active']);
        $foreignCompany = Company::create(['code' => 'CHECK-FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $foreignSite = Site::create(['company_id' => $foreignCompany->id, 'code' => 'CHECK-FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $this->expense();
        $this->preapproval(['company_id' => null]); // Existing catalog permits legacy null company on an authorized site.
        foreach ([['site_id' => $otherSite->id], ['company_id' => $foreignCompany->id, 'site_id' => $foreignSite->id], ['company_id' => $foreignCompany->id]] as $extra) {
            $this->expense($extra);
            $this->preapproval($extra);
        }
        $this->assertSame(2, $this->runCheck('pending_expense_approvals')->last_result['count']);
        foreach ([$this->site, $otherSite, $foreignSite] as $site) {
            ProcurementItem::create(['project_code' => 'CHECK-P', 'wbs_code' => 'W-'.$site->id, 'site_id' => $site->id, 'status' => '발주완료']);
        }
        $this->assertSame(1, $this->runCheck('unreceived_procurement')->last_result['count']);
    }

    public function test_unreceived_procurement_uses_only_known_order_stages_regardless_of_eta(): void
    {
        $statuses = array_merge(ProcurementItem::STATUSES, ['received', 'cancelled', 'unknown', '']);
        $expected = [];
        foreach ($statuses as $i => $status) {
            $row = ProcurementItem::create(['project_code' => 'CHECK-P', 'wbs_code' => 'W-'.$i, 'site_id' => $this->site->id,
                'status' => $status, 'eta' => [null, '2026-10-05', '2026-10-06', '2026-11-01'][$i % 4]]);
            if (in_array($status, ProcurementItem::AWAITING_RECEIPT_STATUSES, true)) {
                $expected[] = $row->id;
            }
        }
        $result = $this->runCheck('unreceived_procurement')->last_result;
        $this->assertSame(4, $result['count']);
        $this->assertSame($expected, array_column($result['records'], 'id'));
        $this->assertStringContainsString('실제 재고나 입고 수량', $result['message']);
    }

    public function test_module_roles_are_intersected_without_expanding_explicit_buyer_grants(): void
    {
        $actor = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_company_id' => $this->company->id,
            'allowed_site_id' => $this->site->id, 'account_status' => 'active', 'purchase_buy_enabled' => true]);
        $actor->companies()->sync([$this->company->id]);
        foreach (['pending_expense_approvals', 'unreceived_procurement'] as $kind) {
            $this->denied(fn () => app(AssistantCheckService::class)->save($actor, ['site_id' => $this->site->id, 'kind' => $kind, 'interval_hours' => 1]));
        }
        $this->assertSame('no_expectation', $this->runCheck('missing_trade_reports', $actor)->last_result['state']);
        $actor->update(['access_role' => 'payroll']);
        $this->denied(fn () => app(AssistantCheckService::class)->save($actor, ['site_id' => $this->site->id, 'kind' => 'missing_trade_reports', 'interval_hours' => 1]));
    }

    public function test_self_and_team_scopes_restrict_both_finance_sources_and_attendance_expectations(): void
    {
        $team = Team::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'code' => 'CHECK-TEAM', 'name' => 'Team', 'status' => 'active']);
        $mine = $this->employee(['team_id' => $team->id]);
        $coworker = $this->employee(['role' => 'Duct']);
        foreach ([$mine, $coworker] as $employee) {
            $this->expense(['employee_id' => $employee->id]);
            $this->preapproval(['employee_id' => $employee->id]);
            $this->clockIn($employee);
        }
        $actor = User::factory()->create(['employee_id' => $mine->id, 'access_role' => 'hr_manager', 'access_scope' => 'self', 'account_status' => 'active',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id, 'allowed_team_id' => $team->id]);
        $actor->companies()->sync([$this->company->id]);
        foreach (['self', 'team'] as $scope) {
            $actor->update(['access_scope' => $scope]);
            $this->assertSame(2, $this->runCheck('pending_expense_approvals', $actor)->last_result['count']);
            $result = $this->runCheck('missing_trade_reports', $actor)->last_result;
            $this->assertSame(['Piping'], array_column($result['records'], 'trade'));
        }
    }

    public function test_report_expectations_use_real_clock_ins_and_existing_report_slot_rules(): void
    {
        $piping = $this->employee();
        $this->clockIn($piping);
        $this->clockIn($piping); // One slot despite repeated clock-ins and multiple workers.
        $this->clockIn($this->employee(), ['status' => 'pending']);
        $this->clockIn($this->employee(['role' => 'Electrical']));
        $this->report('Electrical');
        $this->clockIn($this->employee(['role' => 'Duct']));
        $this->report('Duct', 'open', ['submitted_at' => now()]); // Reopened is not submitted.
        $this->clockIn($this->employee(['role' => '', 'position' => 'safety', 'employment_type' => Employee::TYPE_STAFF]));
        $this->clockIn($this->employee(['role' => 'Client', 'employment_type' => Employee::TYPE_CLIENT]));
        $this->clockIn($this->employee(['role' => '', 'position' => 'worker']));
        $this->clockIn($this->employee(['role' => 'Rejected']), ['status' => 'rejected']);
        $this->clockIn($this->employee(['role' => 'ExitOnly']), ['event_type' => 'clock_out']);
        $this->clockIn($this->employee(['role' => 'Yesterday']), ['attendance_date' => '2026-10-05']);
        $this->report('Empty unused slot', 'open');
        $result = $this->runCheck('missing_trade_reports')->last_result;
        $this->assertSame('missing', $result['state']);
        $this->assertSame(4, $result['expected_count']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(['Duct', 'Piping', '안전'], array_column($result['records'], 'trade'));
        $this->assertSame(['open', 'missing', 'missing'], array_column($result['records'], 'status'));
    }

    public function test_not_due_no_expectation_and_complete_are_distinct_and_holidays_are_not_inferred(): void
    {
        $this->travelTo(Carbon::parse('2026-12-25T21:59:59Z'));
        $this->assertSame('not_due', $this->runCheck('missing_trade_reports')->last_result['state']);
        $this->travelTo(Carbon::parse('2026-12-25T22:00:00Z'));
        $this->assertSame('no_expectation', $this->runCheck('missing_trade_reports')->last_result['state']);
        $this->clockIn($this->employee(), ['attendance_date' => '2026-12-25']);
        $this->assertSame('missing', $this->runCheck('missing_trade_reports')->last_result['state']);
        $this->report('Piping', 'submitted', ['work_date' => '2026-12-25']);
        $this->assertSame('complete', $this->runCheck('missing_trade_reports')->last_result['state']);
    }

    public function test_daily_sources_all_obey_site_and_company_scope_even_with_malformed_attendance_links(): void
    {
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'CLOCK-OTHER', 'name' => 'Other', 'status' => 'active']);
        $foreignCompany = Company::create(['code' => 'CLOCK-FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $foreignSite = Site::create(['company_id' => $foreignCompany->id, 'code' => 'CLOCK-FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $this->clockIn($this->employee());
        $this->report('Piping', 'submitted', ['site_id' => $otherSite->id]);
        $this->clockIn($this->employee(['site_id' => $otherSite->id, 'role' => 'Other site']));
        $foreign = $this->employee(['company_id' => $foreignCompany->id, 'site_id' => $foreignSite->id, 'role' => 'Foreign company']);
        $this->clockIn($foreign, ['company_id' => $this->company->id, 'site_id' => $this->site->id]);
        $local = $this->employee(['role' => 'Wrong log company']);
        $this->clockIn($local, ['company_id' => $foreignCompany->id]);
        $result = $this->runCheck('missing_trade_reports')->last_result;
        $this->assertSame(['Piping'], array_column($result['records'], 'trade'));
        $this->site->update(['status' => 'inactive']);
        $this->assertSame([], app(AssistantCheckService::class)->list($this->owner));
    }

    public function test_daily_report_count_is_exact_above_25_and_uses_the_configured_deadline(): void
    {
        config(['org.ops.trade_report_due_hour' => 18]);
        $this->assertSame('not_due', $this->runCheck('missing_trade_reports')->last_result['state']);
        $this->travelTo(Carbon::parse('2026-10-06T22:00:00Z'));
        for ($i = 0; $i < 26; $i++) {
            $this->clockIn($this->employee(['role' => sprintf('Trade %02d', $i)]));
        }
        $result = $this->runCheck('missing_trade_reports')->last_result;
        $this->assertSame(26, $result['count']);
        $this->assertSame(26, $result['expected_count']);
        $this->assertCount(25, $result['records']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('2026-10-06T18:00:00-04:00', $result['due_at']);
    }

    public static function localClockCases(): array
    {
        return [
            'spring-before' => ['2026-03-08T20:59:59Z', '2026-03-08', 'not_due', '-04:00'],
            'spring-at' => ['2026-03-08T21:00:00Z', '2026-03-08', 'missing', '-04:00'],
            'fall-before' => ['2026-11-01T21:59:59Z', '2026-11-01', 'not_due', '-05:00'],
            'fall-at' => ['2026-11-01T22:00:00Z', '2026-11-01', 'missing', '-05:00'],
            'utc-next-day' => ['2026-10-07T03:59:59Z', '2026-10-06', 'missing', '-04:00'],
            'site-midnight' => ['2026-10-07T04:00:00Z', '2026-10-07', 'not_due', '-04:00'],
        ];
    }

    #[DataProvider('localClockCases')]
    public function test_report_cutoff_and_date_follow_site_clock_across_dst(string $instant, string $date, string $state, string $offset): void
    {
        $this->travelTo(Carbon::parse($instant));
        $this->clockIn($this->employee(), ['attendance_date' => $date]);
        $result = $this->runCheck('missing_trade_reports')->last_result;
        $this->assertSame($state, $result['state']);
        $this->assertSame($date, $result['site_date']);
        $this->assertSame($date.'T17:00:00'.$offset, $result['due_at']);
        $this->assertSame('America/New_York', $result['timezone']);
    }

    public function test_daily_check_rejects_nonhourly_input_and_never_activates_a_tampered_interval(): void
    {
        $this->actingAsPurchaseUser($this->owner);
        foreach ([6, 24] as $hours) {
            $this->postJson('/ask-api/workspace/checks', ['site_id' => $this->site->id, 'kind' => 'missing_trade_reports', 'interval_hours' => $hours])
                ->assertUnprocessable()->assertJsonValidationErrors('interval_hours');
        }
        $service = app(AssistantCheckService::class);
        $check = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'missing_trade_reports', 'interval_hours' => 1]);
        $check->update(['interval_hours' => 24]);
        $this->postJson('/ask-api/workspace/checks/'.$check->id.'/activate', ['confirmed' => true, 'version' => $check->approval_version])->assertUnprocessable();
        $this->assertFalse($check->fresh()->enabled);
        $check->update(['enabled' => true, 'approved_at' => now(), 'next_run_at' => now()]);
        $other = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'pending_expense_approvals', 'interval_hours' => 6]);
        $service->activate($this->owner, $other->id, true, $other->approval_version);
        $this->assertSame(1, $service->runDue());
        $this->assertNotNull($other->fresh()->last_run_at);
        $this->assertFalse($check->fresh()->enabled);
    }

    public function test_revocation_and_live_source_changes_remove_old_results_without_outbound_or_business_writes(): void
    {
        $employee = $this->employee();
        $attendance = $this->clockIn($employee);
        $report = $this->report('Piping', 'open');
        $expense = $this->expense();
        $this->preapproval();
        $writtenTables = [];
        DB::listen(function ($query) use (&$writtenTables): void {
            if (preg_match('/^(?:insert into|update|delete from) "?([a-z_]+)/i', $query->sql, $matches)) {
                $writtenTables[] = $matches[1];
            }
        });
        $daily = $this->runCheck('missing_trade_reports');
        $this->runCheck('pending_expense_approvals');
        $this->runCheck('unreceived_procurement');
        $this->assertSame(['assistant_checks'], array_values(array_unique($writtenTables)));
        $report->update(['status' => 'submitted']);
        $expense->update(['status' => 'approved']);
        $results = collect(app(AssistantCheckService::class)->list($this->owner))->keyBy('kind');
        $this->assertSame('complete', $results['missing_trade_reports']['result']['state']);
        $this->assertSame(1, $results['pending_expense_approvals']['result']['count']);
        $attendance->update(['status' => 'rejected']);
        $this->assertSame('no_expectation', collect(app(AssistantCheckService::class)->list($this->owner))->keyBy('kind')['missing_trade_reports']['result']['state']);
        $this->owner->update(['account_status' => 'suspended']);
        $daily->update(['next_run_at' => now()]);
        $this->assertSame([], app(AssistantCheckService::class)->list($this->owner));
        $this->assertSame(0, app(AssistantCheckService::class)->runDue());
        $this->assertFalse($daily->fresh()->enabled);
        $this->assertNull($daily->fresh()->last_result);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
