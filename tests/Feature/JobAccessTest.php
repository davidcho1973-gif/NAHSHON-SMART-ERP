<?php

namespace Tests\Feature;

use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\Equipment;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\PayrollRun;
use App\Models\PayrollTimesheet;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Admin\AttendanceLogAdminService;
use App\Services\Admin\JobAccessService;
use App\Services\Admin\JobApprovalService;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\ManagerInvitationService;
use App\Services\Equipment\EquipmentChecklistService;
use App\Services\Payroll\PayrollCalculator;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use App\Support\JobEndpointPolicy;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class JobAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $company = Company::create(['code' => 'JOB', 'name' => 'Job company', 'status' => 'active']);
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other company', 'status' => 'active']);
        $site = Site::create(['code' => 'JOB-A', 'name' => 'Job A', 'company_id' => $company->id, 'status' => 'active']);
        $second = Site::create(['code' => 'JOB-B', 'name' => 'Job B', 'company_id' => $company->id, 'status' => 'active']);
        $foreign = Site::create(['code' => 'JOB-X', 'name' => 'Foreign', 'company_id' => $other->id, 'status' => 'active']);

        return [$company, $site, $second, $foreign];
    }

    private function profile(string $role, Company $company, array $sites, array $extra = []): User
    {
        $grant = JobAccess::grant(array_merge(['jobRole' => $role, 'companyId' => $company->id, 'scope' => 'site', 'siteIds' => array_map(fn ($site) => $site->id, $sites)], $extra));
        $user = User::factory()->create(['account_status' => 'active']);
        $user->forceFill($grant)->save();

        return $user;
    }

    public function test_defaults_separate_hr_payroll_accounting_and_payment_authority(): void
    {
        $hr = JobAccess::defaults('office', ['hr']);
        $payroll = JobAccess::defaults('office', ['payroll']);
        $accounting = JobAccess::defaults('office', ['accounting']);
        $this->assertArrayHasKey('private_hr', $hr);
        $this->assertArrayNotHasKey('finance', $hr);
        $this->assertArrayNotHasKey('payroll', $hr);
        $this->assertContains('edit', $payroll['payroll']);
        $this->assertNotContains('approve', $payroll['payroll']);
        $this->assertNotContains('pay', $payroll['payroll']);
        $this->assertArrayNotHasKey('payroll', $accounting);
        $this->assertArrayNotHasKey('system', JobAccess::defaults('president'));
    }

    public function test_scoped_site_manager_cannot_call_payroll_finance_hr_or_unknown_api(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('site_manager', $company, [$site]);
        $this->actingAsPurchaseUser($user);
        foreach (['api_getPayrollDashboard', 'api_getFinanceStats', 'api_getPayProfiles', 'api_getUserAccessList', 'api_futureDangerousGetter'] as $method) {
            $this->postJson('/smart-company-api/'.$method, ['args' => []])->assertForbidden();
        }
        $this->assertFalse(app(UserAccessService::class)->canManage());
        $this->get('/payroll/run/999/certified')->assertForbidden();
    }

    public function test_site_and_company_scopes_survive_legacy_admin_carrier_role(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        $firstEmployee = Employee::create(['name' => 'First', 'company_id' => $company->id, 'site_id' => $site->id]);
        Employee::create(['name' => 'Second', 'company_id' => $company->id, 'site_id' => $second->id]);
        Employee::create(['name' => 'Foreign', 'company_id' => $foreign->company_id, 'site_id' => $foreign->id]);
        $user = $this->profile('site_manager', $company, [$site]);
        $this->actingAsPurchaseUser($user);
        $this->assertSame([$firstEmployee->id], Employee::pluck('id')->all());
        $this->assertSame([$site->id], Site::pluck('id')->all());
        $this->assertFalse(AiInformationAccess::canUseSite($user, $foreign));
        $this->assertFalse(PurchaseAccess::canUseSite($user, $second));
        $this->assertSame([$company->id], $user->accessibleCompanies()->modelKeys());
    }

    public function test_multiple_sites_are_supported_without_other_company_access(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        $user = $this->profile('engineering', $company, [$site, $second]);
        $this->actingAsPurchaseUser($user);
        $this->assertSame([$site->id, $second->id], Site::orderBy('id')->pluck('id')->all());
        $this->assertTrue(AiInformationAccess::canUseSite($user, $second));
        $this->assertFalse(AiInformationAccess::canUseSite($user, $foreign));
    }

    public function test_team_scope_excludes_other_teams_in_the_same_site(): void
    {
        [$company, $site] = $this->fixtures();
        $team = Team::create(['code' => 'PIPE', 'name' => 'Pipe', 'site_id' => $site->id, 'company_id' => $company->id]);
        $other = Team::create(['code' => 'DUCT', 'name' => 'Duct', 'site_id' => $site->id, 'company_id' => $company->id]);
        $employee = Employee::create(['name' => 'Pipe worker', 'company_id' => $company->id, 'site_id' => $site->id, 'team_id' => $team->id]);
        Employee::create(['name' => 'Duct worker', 'company_id' => $company->id, 'site_id' => $site->id, 'team_id' => $other->id]);
        $user = User::factory()->create(['account_status' => 'active']);
        $user->forceFill(JobAccess::grant(['jobRole' => 'trade_lead', 'companyId' => $company->id, 'siteIds' => [$site->id], 'scope' => 'team', 'teamId' => $team->id]))->save();
        $this->actingAsPurchaseUser($user);
        $this->assertSame([$employee->id], Employee::pluck('id')->all());
        $this->assertSame([$team->id], Team::pluck('id')->all());
    }

    public function test_cross_scope_writes_are_rejected_even_after_a_permitted_endpoint(): void
    {
        [$company, $site, $second] = $this->fixtures();
        $user = $this->profile('office', $company, [$site], ['jobDuties' => ['hr']]);
        $this->actingAsPurchaseUser($user);
        $this->expectException(HttpException::class);
        Employee::create(['name' => 'Forged scope', 'company_id' => $company->id, 'site_id' => $second->id]);
    }

    public function test_sensitive_employee_fields_are_removed_for_site_and_safety_roles(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('safety', $company, [$site]);
        $data = JobEndpointPolicy::redact(['rows' => [['name' => 'Worker', 'phone' => '123', 'w9TinLast4' => '4321', 'baseRate' => 55, 'loginEmail' => 'secret@example.test']]], $user);
        $this->assertSame(['rows' => [['name' => 'Worker']]], $data);
        $this->assertFalse(JobAccess::financialQuestionAllowed($user, '직원 시급 얼마야?'));
    }

    public function test_president_and_combined_office_duties_do_not_inherit_system_or_payment(): void
    {
        [$company, $site] = $this->fixtures();
        $president = $this->profile('president', $company, [$site]);
        $this->assertTrue(JobAccess::can($president, 'payroll', 'approve'));
        $this->assertFalse(JobAccess::can($president, 'payroll', 'pay'));
        $this->assertFalse(JobAccess::can($president, 'system'));
        $office = $this->profile('office', $company, [$site], ['jobDuties' => ['hr', 'payroll', 'purchasing']]);
        $this->assertTrue(JobAccess::can($office, 'people', 'edit'));
        $this->assertTrue(JobAccess::can($office, 'payroll', 'edit'));
        $this->assertTrue(PurchaseAccess::hasBuyerPermission($office));
        $this->assertFalse(JobAccess::can($office, 'finance'));
    }

    public function test_only_strong_superadmin_assigns_profile_and_keeps_employee_identity(): void
    {
        [$company, $site] = $this->fixtures();
        $employee = Employee::create(['name' => 'Existing', 'company_id' => $company->id, 'site_id' => $site->id]);
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active', 'access_role' => 'worker']);
        $input = ['jobRole' => 'site_manager', 'companyId' => $company->id, 'scope' => 'site', 'siteIds' => [$site->id]];
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $this->assertFalse(app(JobAccessService::class)->save($user->id, $input)['success']);
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $this->assertTrue(app(JobAccessService::class)->save($user->id, $input)['success']);
        $this->assertSame($employee->id, $user->fresh()->employee_id);
        $this->assertDatabaseHas('auth_events', ['event' => 'job_permissions_changed', 'user_id' => $user->id]);
    }

    public function test_custom_permissions_and_invalid_company_sites_are_validated(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $target = User::factory()->create();
        $input = ['jobRole' => 'site_manager', 'companyId' => $company->id, 'scope' => 'site', 'siteIds' => [$foreign->id]];
        $this->assertFalse(app(JobAccessService::class)->save($target->id, $input)['success']);
        $input['siteIds'] = [$site->id];
        $input['jobPermissions'] = ['system' => ['view', 'edit']];
        $this->assertFalse(app(JobAccessService::class)->save($target->id, $input)['success']);
        $input['jobPermissions'] = ['attendance' => ['view'], 'payroll' => ['view']];
        $this->assertTrue(app(JobAccessService::class)->save($target->id, $input)['success']);
        $this->assertTrue(JobAccess::can($target->fresh(), 'payroll'));
        $this->assertFalse(JobAccess::can($target->fresh(), 'attendance', 'edit'));
    }

    public function test_personal_app_has_job_workspace_and_erp_hides_payroll_menu(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('site_manager', $company, [$site]);
        $this->actingAsPurchaseUser($user);
        $this->get('/attendance-app/workspace')->assertOk()->assertSee('소장 · 관리업무')->assertDontSee('급여·정산');
        $this->get('/attendance-app')->assertOk()->assertSee('/attendance-app/workspace', false);
        $this->getJson('/attendance-app/workspace')->assertOk()->assertJsonPath('label', '소장');
    }

    public function test_weak_phone_entry_cannot_open_management_workspace_or_approve(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('president', $company, [$site]);
        $this->actingAs($user)->withSession([WorkerDeviceSession::FLAG => true]);
        $this->getJson('/attendance-app/workspace')->assertForbidden();
        $this->postJson('/job-approvals/contracts/123', ['decision' => 'approved', 'version' => 'old'])->assertForbidden();
    }

    public function test_invitation_accepts_server_selected_office_duties_without_duplicate_employee(): void
    {
        [$company, $site] = $this->fixtures();
        $employee = Employee::create(['name' => 'Worker', 'phone' => '+12025550147', 'company_id' => $company->id, 'site_id' => $site->id]);
        $worker = User::factory()->create(['employee_id' => $employee->id, 'email' => null, 'google_id' => null, 'password_set_at' => null, 'access_role' => 'worker', 'account_status' => 'active']);
        $owner = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($owner);
        $result = app(ManagerInvitationService::class)->issue(['id' => $worker->id, 'jobRole' => 'office', 'jobDuties' => ['payroll'], 'companyId' => $company->id, 'scope' => 'company']);
        $this->assertTrue($result['success'], json_encode($result));
        Auth::logout();
        $this->flushSession();
        $this->post($result['url'].'/verify', ['phone' => '2025550147'])->assertRedirect();
        $this->post($result['url'].'/complete', ['email' => 'office@example.test', 'password' => 'Password12345', 'password_confirmation' => 'Password12345', 'jobPermissions' => ['system' => ['view']]])->assertRedirect();
        $fresh = $worker->fresh();
        $this->assertSame('office', $fresh->job_role);
        $this->assertSame($employee->id, $fresh->employee_id);
        $this->assertTrue(JobAccess::can($fresh, 'payroll', 'edit'));
        $this->assertFalse(JobAccess::can($fresh, 'payroll', 'pay'));
        $this->assertFalse(JobAccess::can($fresh, 'system'));
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_new_staff_invitation_uses_the_assigned_job_and_company(): void
    {
        [$company, $site] = $this->fixtures();
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $result = app(ManagerInvitationService::class)->issue(['kind' => 'new_employee', 'jobRole' => 'safety', 'companyId' => $company->id, 'scope' => 'site', 'siteIds' => [$site->id]]);
        $this->assertTrue($result['success'], json_encode($result));
        Auth::logout();
        $this->flushSession();
        $this->post($result['url'].'/verify', ['name' => 'New safety', 'phone' => '2025550147'])->assertRedirect();
        $this->post($result['url'].'/complete', ['email' => 'safety@example.test', 'password' => 'Password12345', 'password_confirmation' => 'Password12345'])->assertRedirect();
        $user = User::where('email', 'safety@example.test')->firstOrFail();
        $this->assertSame('safety', $user->job_role);
        $this->assertSame('safety', $user->employee->position);
        $this->assertTrue(JobAccess::can($user, 'safety', 'edit'));
        $this->assertFalse(JobAccess::can($user, 'finance'));
    }

    public function test_purchase_approval_and_parent_rows_stay_in_assigned_sites(): void
    {
        [$company, $site, $second] = $this->fixtures();
        $requester = User::factory()->create();
        $make = fn ($s) => PurchaseRequest::create(['company_id' => $company->id, 'site_id' => $s->id, 'requested_by_id' => $requester->id, 'request_key' => (string) Str::uuid(), 'request_fingerprint' => 'fingerprint', 'status' => 'submitted', 'approval_required' => true, 'approval_status' => 'pending']);
        $request = $make($site);
        $outside = $make($second);
        $line = $request->lines()->create(['seq' => 0, 'name' => 'Pipe', 'quantity' => 10, 'unit' => 'EA']);
        $outside->lines()->create(['seq' => 0, 'name' => 'Other pipe', 'quantity' => 20, 'unit' => 'EA']);
        $president = $this->profile('president', $company, [$site]);
        $this->actingAsPurchaseUser($president);
        $this->assertSame([$line->id], PurchaseRequestLine::pluck('id')->all());
        $this->postJson('/job-approvals/purchasing/'.$outside->id, ['decision' => 'approved', 'version' => JobApprovalService::version($outside), 'budget' => 100, 'currency' => 'USD'])->assertNotFound();
        $this->postJson('/job-approvals/purchasing/'.$request->id, ['decision' => 'approved', 'version' => JobApprovalService::version($request->fresh()), 'budget' => 100, 'currency' => 'USD'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('approved', $request->fresh()->approval_status);
        $this->assertDatabaseHas('auth_events', ['event' => 'job_approval', 'user_id' => $president->id]);
        $buyer = $this->profile('office', $company, [$site], ['jobDuties' => ['purchasing']]);
        $this->actingAsPurchaseUser($buyer);
        $this->postJson('/job-approvals/purchasing/'.$request->id, ['decision' => 'approved', 'version' => 'old', 'budget' => 100, 'currency' => 'USD'])->assertForbidden();
        $this->postJson('/purchase-requests/'.$request->id.'/action', ['action' => 'order', 'version' => $request->fresh()->version, 'vendor' => 'Vendor', 'order_number' => 'PO-A', 'amount' => 101, 'currency' => 'USD', 'order_lines' => [['request_line_id' => $line->id, 'quantity' => 10]]])->assertStatus(422);
    }

    public function test_contract_officer_cannot_read_mixed_payroll_documents_or_other_site_children(): void
    {
        [$company, $site, $second] = $this->fixtures();
        $make = fn ($s, $title, $type) => IntelligentDocument::create(['company_id' => $company->id, 'site_id' => $s->id, 'uuid' => (string) Str::uuid(), 'disk' => 'local', 'file_path' => 'test.txt', 'original_file_name' => 'test.txt', 'stored_file_name' => 'test.txt', 'sha256' => hash('sha256', $title), 'mime_type' => 'text/plain', 'title' => $title, 'document_type' => $type, 'ai_status' => 'ready', 'confidentiality' => 'internal']);
        $safe = $make($site, 'Drawing A', 'drawing');
        $make($site, '직원 시급 payroll contract', 'pay_application');
        $make($site, 'Payroll', 'payroll_record');
        $make($second, 'Other drawing', 'drawing');
        $officer = $this->profile('engineering', $company, [$site]);
        $this->actingAsPurchaseUser($officer);
        $this->assertSame([$safe->id], IntelligentDocument::pluck('id')->all());
    }

    public function test_payroll_runs_cannot_leak_other_company_totals_and_personal_payslip_is_own_only(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        $employee = Employee::create(['name' => 'Own worker', 'company_id' => $company->id, 'site_id' => $site->id]);
        $other = Employee::create(['name' => 'Foreign worker', 'company_id' => $foreign->company_id, 'site_id' => $foreign->id]);
        $run = PayrollRun::create(['code' => 'MIXED', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'status' => 'paid']);
        $own = $run->payslips()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'status' => 'paid', 'snap_pay_type' => 'hourly', 'snap_base_rate' => 10, 'gross_pay' => 100, 'net_pay' => 90]);
        $otherSlip = $run->payslips()->create(['employee_id' => $other->id, 'company_id' => $foreign->company_id, 'status' => 'paid', 'snap_pay_type' => 'hourly', 'snap_base_rate' => 10, 'gross_pay' => 999, 'net_pay' => 990]);
        $user = $this->profile('office', $company, [$site], ['jobDuties' => ['payroll']]);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $this->actingAsPurchaseUser($user);
        $this->assertSame([], PayrollRun::pluck('id')->all());
        $this->get('/attendance-app/payslips/'.$own->id)->assertOk()->assertSee('Own worker')->assertDontSee('Foreign worker');
        $this->get('/attendance-app/payslips/'.$otherSlip->id)->assertNotFound();
        $this->withSession([WorkerDeviceSession::FLAG => true])->get('/attendance-app/payslips/'.$own->id)->assertForbidden();
    }

    public function test_approval_only_president_can_approve_attendance_but_editor_cannot(): void
    {
        [$company, $site] = $this->fixtures();
        $employee = Employee::create(['name' => 'Worker', 'company_id' => $company->id, 'site_id' => $site->id]);
        $log = AttendanceLog::create(['employee_id' => $employee->id, 'company_id' => $company->id, 'site_id' => $site->id, 'attendance_date' => '2026-10-06', 'event_type' => 'clock_in', 'event_at' => '2026-10-06 07:00:00', 'source' => 'manual', 'status' => 'pending']);
        $editor = $this->profile('site_manager', $company, [$site], ['jobPermissions' => ['attendance' => ['view', 'edit']]]);
        $this->actingAsPurchaseUser($editor);
        $this->assertFalse(app(AttendanceLogAdminService::class)->setStatus($log->id, 'approved')['success']);
        $this->assertFalse(app(AttendanceLogAdminService::class)->canDelete());
        $president = $this->profile('president', $company, [$site]);
        $this->actingAsPurchaseUser($president);
        $this->postJson('/smart-company-api/api_setAttendanceLogStatus', ['args' => [$log->id, 'approved']])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('approved', $log->fresh()->status);
    }

    public function test_team_lead_sees_and_writes_only_their_trade_on_the_week_board(): void
    {
        [$company, $site] = $this->fixtures();
        $team = Team::create(['code' => 'PIPE', 'name' => 'Pipe', 'trade_type' => '배관', 'site_id' => $site->id, 'company_id' => $company->id]);
        $pipe = WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $site->id, 'week_start' => '2026-10-05', 'trade' => '배관', 'task' => 'Pipe']);
        WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $site->id, 'week_start' => '2026-10-05', 'trade' => '덕트', 'task' => 'Duct']);
        $user = User::factory()->create(['account_status' => 'active']);
        $user->forceFill(JobAccess::grant(['jobRole' => 'trade_lead', 'companyId' => $company->id, 'siteIds' => [$site->id], 'scope' => 'team', 'teamId' => $team->id]))->save();
        $this->actingAsPurchaseUser($user);
        $this->assertSame([$pipe->id], WeekBoardLine::pluck('id')->all());
        $this->expectException(HttpException::class);
        WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $site->id, 'week_start' => '2026-10-05', 'trade' => '덕트', 'task' => 'Forged']);
    }

    public function test_mcp_enforces_module_permissions_with_explicit_actor_context(): void
    {
        [$company, $site] = $this->fixtures();
        $manager = $this->profile('site_manager', $company, [$site]);
        $this->actingAsPurchaseUser(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $context = new ErpReadContext($manager, $company->id);
        $this->assertSame([$site->id], $context->siteIds);
        $this->assertSame('reports', JobAccess::datasetModule('sites'));
        $this->assertSame([$company->id], app(ErpReadQuery::class)->query('companies', $context)->pluck('id')->all());
        app(ErpReadQuery::class)->query('employees', $context)->limit(1)->get();
        $this->expectException(HttpException::class);
        app(ErpReadQuery::class)->query('payslips', $context)->get();
    }

    public function test_payment_requires_separate_authority_approval_and_current_record(): void
    {
        [$company, $site, $second] = $this->fixtures();
        $expense = MobileExpense::create(['company_id' => $company->id, 'site_id' => $site->id, 'description' => 'Expense', 'payment_type' => 'personal_card', 'category' => 'supplies', 'amount' => 35, 'expense_date' => '2026-10-06', 'status' => 'pending']);
        $outside = MobileExpense::create(['company_id' => $company->id, 'site_id' => $second->id, 'description' => 'Outside', 'payment_type' => 'personal_card', 'category' => 'supplies', 'amount' => 99, 'expense_date' => '2026-10-06', 'status' => 'approved']);
        $payer = $this->profile('office', $company, [$site], ['jobPermissions' => ['finance' => ['view', 'pay']]]);
        $this->actingAsPurchaseUser($payer);
        $this->postJson('/job-payments/finance/'.$expense->id, ['decision' => 'paid', 'version' => JobApprovalService::version($expense->fresh())])->assertStatus(409);
        $this->postJson('/job-approvals/finance/'.$expense->id, ['decision' => 'approved', 'version' => JobApprovalService::version($expense->fresh())])->assertForbidden();
        $this->postJson('/job-payments/finance/'.$outside->id, ['decision' => 'paid', 'version' => JobApprovalService::version($outside)])->assertNotFound();
        $president = $this->profile('president', $company, [$site]);
        $this->actingAsPurchaseUser($president);
        $this->postJson('/job-approvals/finance/'.$expense->id, ['decision' => 'approved', 'version' => JobApprovalService::version($expense->fresh())])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/job-payments/finance/'.$expense->id, ['decision' => 'paid', 'version' => JobApprovalService::version($expense->fresh())])->assertForbidden();
        $this->actingAsPurchaseUser($payer);
        $this->postJson('/job-payments/finance/'.$expense->id, ['decision' => 'paid', 'version' => 'stale'])->assertStatus(409);
        $version = JobApprovalService::version($expense->fresh());
        $this->postJson('/job-payments/finance/'.$expense->id, ['decision' => 'paid', 'version' => $version])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/job-payments/finance/'.$expense->id, ['decision' => 'paid', 'version' => $version])->assertStatus(409);
        $this->assertDatabaseHas('mobile_expenses', ['id' => $expense->id, 'status' => 'paid', 'paid_by_user_id' => $payer->id]);
    }

    public function test_managed_worker_retains_own_gps_status_and_push_settings_on_phone_login(): void
    {
        [$company, $site] = $this->fixtures();
        $employee = Employee::create(['name' => 'Worker', 'company_id' => $company->id, 'site_id' => $site->id]);
        $user = $this->profile('worker', $company, [$site], ['scope' => 'self']);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $this->actingAs($user)->withSession([WorkerDeviceSession::FLAG => true]);
        $this->getJson('/attendance-geo/status')->assertOk();
        $this->getJson('/push/key')->assertOk();
        $this->getJson('/attendance-app/workspace')->assertForbidden();
    }

    public function test_read_only_materials_permission_shows_records_without_mutation_controls(): void
    {
        [$company, $site] = $this->fixtures();
        $president = $this->profile('president', $company, [$site]);
        $this->actingAsPurchaseUser($president);
        $this->postJson('/smart-company-api/api_getMaterialReceipts', ['args' => ['ALL']])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('canManage', false);
        $this->postJson('/smart-company-api/api_saveMaterialReceipt', ['args' => [[]]])->assertForbidden();
        $flags = JobEndpointPolicy::redact(['canManage' => true, 'canDelete' => true], $president, 'materials');
        $this->assertFalse($flags['canManage']);
        $this->assertFalse($flags['canDelete']);
    }

    public function test_managed_payroll_calculation_reuses_scoped_run_and_never_includes_other_company(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        $employee = Employee::create(['name' => 'Payroll worker', 'company_id' => $company->id, 'site_id' => $site->id, 'employment_status' => 'active']);
        $other = Employee::create(['name' => 'Foreign worker', 'company_id' => $foreign->company_id, 'site_id' => $foreign->id, 'employment_status' => 'active']);
        foreach ([$employee, $other] as $person) {
            EmployeePayrollProfile::updateOrCreate(['employee_id' => $person->id], ['company_id' => $person->company_id, 'site_id' => $person->site_id, 'pay_type' => 'hourly', 'base_rate' => 20]);
            PayrollTimesheet::create(['employee_id' => $person->id, 'company_id' => $person->company_id, 'site_id' => $person->site_id, 'work_date' => '2026-10-06', 'regular_minutes' => 480, 'status' => 'approved']);
        }
        $user = $this->profile('office', $company, [$site], ['jobDuties' => ['payroll']]);
        $this->actingAsPurchaseUser($user);
        $calculator = app(PayrollCalculator::class);
        $run = $calculator->runPayroll('2026-10-05', 'ALL', $user->id);
        $this->assertSame([$employee->id], $run->payslips->pluck('employee_id')->all());
        $again = $calculator->runPayroll('2026-10-05', 'ALL', $user->id);
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, $again->payslips()->count());
    }

    public function test_safety_editor_cannot_approve_a_plan_with_the_save_flag(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('safety', $company, [$site], ['jobPermissions' => ['safety' => ['view', 'edit']]]);
        $this->actingAsPurchaseUser($user);
        $this->postJson('/smart-company-api/api_saveSafetyPlan', ['args' => ['missing', [], true]])->assertForbidden();
    }

    public function test_worker_report_writing_does_not_grant_final_report_dispatch(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('worker', $company, [$site], ['scope' => 'self']);
        $this->actingAsPurchaseUser($user);
        $this->assertTrue(JobAccess::can($user, 'reports', 'edit'));
        $this->postJson('/smart-company-api/api_sendDailyReport', ['args' => []])->assertForbidden();
    }

    public function test_empty_managed_payroll_does_not_create_a_hidden_run(): void
    {
        [$company, $site] = $this->fixtures();
        $user = $this->profile('office', $company, [$site], ['jobDuties' => ['payroll']]);
        $this->actingAsPurchaseUser($user);
        try {
            app(PayrollCalculator::class)->runPayroll('2026-10-05', 'ALL', $user->id);
            $this->fail('An empty managed run must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payroll_runs', 0);
    }

    public function test_worker_keeps_personal_document_upload_and_equipment_checks_without_management_access(): void
    {
        [$company, $site, $second, $foreign] = $this->fixtures();
        Storage::fake('public');
        Queue::fake();
        $employee = Employee::create(['name' => 'Worker', 'company_id' => $company->id, 'site_id' => $site->id, 'employment_status' => 'active']);
        $equipment = Equipment::create(['company_id' => $company->id, 'site_id' => $site->id, 'equipment_type' => 'Excavator', 'model' => 'CAT 320', 'category_group' => 'equipment', 'trade' => 'heavy', 'status' => Equipment::STATUS_AVAILABLE]);
        $token = $equipment->ensureQrToken();
        $checklists = app(EquipmentChecklistService::class);
        $template = $checklists->templateFor($equipment, 'pre_use');
        $answers = $template->items->mapWithKeys(fn ($item) => [$item->id => ['ok' => true]])->all();
        $user = $this->profile('worker', $company, [$site], ['scope' => 'self']);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $this->actingAs($user)->withSession([WorkerDeviceSession::FLAG => true]);
        $this->get('/attendance-app/docs')->assertOk();
        $this->post('/docs-api/upload', ['site_id' => $site->id, 'file' => UploadedFile::fake()->create('Plan.dwg', 1, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(201);
        $this->post('/docs-api/upload', ['site_id' => $foreign->id, 'file' => UploadedFile::fake()->create('Other.dwg', 1, 'application/octet-stream')], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/eq/'.$token)->assertOk();
        $this->postJson('/eq/'.$token.'/submit', ['stage' => 'pre_use', 'answers' => $answers])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/smart-company-api/api_getEquipmentList', ['args' => []])->assertForbidden();
        Auth::logout();
        $team = Team::create(['code' => 'QR-TEAM', 'name' => 'Pipe team', 'trade_type' => '배관', 'company_id' => $company->id, 'site_id' => $site->id]);
        $user->forceFill(JobAccess::grant(['jobRole' => 'trade_lead', 'scope' => 'team', 'teamId' => $team->id, 'companyId' => $company->id, 'siteIds' => [$site->id]], $user))->save();
        $this->actingAs($user)->withSession([WorkerDeviceSession::FLAG => true]);
        $this->get('/eq/'.$token)->assertOk();
    }
}
