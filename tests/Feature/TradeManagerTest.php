<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\PurchaseRequest;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Admin\JobAccessService;
use App\Services\Auth\ManagerInvitationService;
use App\Services\GeminiReceiptAnalyzer;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use App\Support\PurchaseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TradeManagerTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $company = Company::create(['code' => 'TRADE', 'name' => 'Trade company', 'status' => 'active']);
        $site = Site::create(['code' => 'TRADE-A', 'name' => 'A', 'company_id' => $company->id, 'status' => 'active']);
        $second = Site::create(['code' => 'TRADE-B', 'name' => 'B', 'company_id' => $company->id, 'status' => 'active']);
        $team = fn ($code, $site, $trade) => Team::create(['code' => $code, 'name' => $code, 'company_id' => $company->id, 'site_id' => $site->id, 'trade_type' => $trade]);

        return [$company, $site, $second, $team('PIPE-A', $site, '배관'), $team('PIPE-B', $second, '배관'), $team('DUCT-A', $site, '덕트')];
    }

    private function user(Company $company, array $sites, string $role, ?Team $team = null): User
    {
        $employee = Employee::create(['name' => $role, 'company_id' => $company->id, 'site_id' => $sites[0]->id, 'team_id' => $team?->id, 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active']);
        $user->forceFill(JobAccess::grant(['jobRole' => $role, 'companyId' => $company->id, 'siteIds' => array_map(fn ($s) => $s->id, $sites),
            'scope' => $role === 'trade_manager' ? 'trade' : 'team', 'teamId' => $team?->id, 'jobTrade' => '배관']))->save();

        return $user;
    }

    public function test_trade_manager_sees_multiple_pipe_teams_but_not_duct_or_unassigned_sites(): void
    {
        [$company, $site, $second, $pipeA, $pipeB, $duct] = $this->fixtures();
        $a = $this->user($company, [$site], 'trade_lead', $pipeA);
        $b = $this->user($company, [$second], 'trade_lead', $pipeB);
        $d = $this->user($company, [$site], 'trade_lead', $duct);
        $manager = $this->user($company, [$site, $second], 'trade_manager');
        foreach ([$a, $b, $d] as $actor) {
            PurchaseRequest::create(['company_id' => $company->id, 'site_id' => $actor->allowed_site_id, 'requested_by_id' => $actor->id, 'status' => 'submitted', 'request_key' => (string) Str::uuid(), 'request_fingerprint' => 'fixture']);
        }
        foreach (['배관', '덕트'] as $trade) {
            WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $site->id, 'week_start' => '2026-10-05', 'trade' => $trade, 'task' => $trade, 'status' => 'planned']);
        }
        $this->actingAsPurchaseUser($manager);
        $this->assertEqualsCanonicalizing([$pipeA->id, $pipeB->id], Team::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->employee_id, $b->employee_id, $manager->employee_id], Employee::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], PurchaseRequest::pluck('requested_by_id')->all());
        $this->assertSame(['배관'], WeekBoardLine::pluck('trade')->all());
        $this->assertTrue(JobAccess::can($manager, 'attendance', 'approve'));
        $this->assertTrue(JobAccess::can($manager, 'reports', 'approve'));
        $this->assertFalse(PurchaseAccess::canBuy($manager));
        $this->assertFalse(JobAccess::can($manager, 'finance'));
        $this->assertFalse(JobAccess::can($manager, 'payroll'));
        $manager->forceFill(['job_site_ids' => [$site->id]])->save();
        $this->assertSame([$pipeA->id], Team::pluck('id')->all());
        $this->assertFalse(Employee::whereKey($b->employee_id)->exists());
    }

    public function test_trade_manager_cannot_write_another_trade_team(): void
    {
        [$company, $site, , , , $duct] = $this->fixtures();
        $manager = $this->user($company, [$site], 'trade_manager');
        $this->actingAsPurchaseUser($manager);
        $this->postJson('/smart-company-api/api_saveEmployee', ['args' => [['name' => 'Forged', 'companyId' => $company->id, 'siteId' => $site->id, 'teamId' => $duct->id]]])->assertForbidden();
        $this->expectException(HttpException::class);
        WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $site->id, 'week_start' => '2026-10-05', 'trade' => '덕트', 'task' => 'Forged']);
    }

    public function test_manager_grant_requires_real_trade_and_cannot_replace_trade_scope_with_company(): void
    {
        [$company, $site] = $this->fixtures();
        foreach ([['scope' => 'trade', 'jobTrade' => '전기'], ['scope' => 'company', 'jobTrade' => '배관']] as $extra) {
            try {
                JobAccess::grant($extra + ['jobRole' => 'trade_manager', 'companyId' => $company->id, 'siteIds' => [$site->id]]);
                $this->fail('Invalid trade scope must be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('jobTrade', $e->errors());
            }
        }
    }

    public function test_trade_manager_write_scope_rejects_an_employee_assigned_to_another_trade(): void
    {
        [$company, $site, , , , $duct] = $this->fixtures();
        $manager = $this->user($company, [$site], 'trade_manager');
        $this->actingAsPurchaseUser($manager);
        $this->expectException(HttpException::class);
        Employee::create(['name' => 'Other trade', 'company_id' => $company->id, 'site_id' => $site->id, 'team_id' => $duct->id]);
    }

    public function test_promoting_existing_foreman_keeps_employee_identity_and_updates_position(): void
    {
        [$company, $site, , $pipe] = $this->fixtures();
        $foreman = $this->user($company, [$site], 'trade_lead', $pipe);
        $employeeId = $foreman->employee_id;
        $root = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($root);
        $result = app(JobAccessService::class)->save($foreman->id, ['jobRole' => 'trade_manager', 'companyId' => $company->id, 'siteIds' => [$site->id], 'scope' => 'trade', 'jobTrade' => '배관']);
        $this->assertTrue($result['success']);
        $this->assertSame($employeeId, $foreman->fresh()->employee_id);
        $this->assertSame('trade_manager', $foreman->fresh()->employee->position);
    }

    public function test_trade_change_invalidates_ai_context_and_pending_invitation_identity(): void
    {
        [$company, $site] = $this->fixtures();
        $manager = $this->user($company, [$site], 'trade_manager');
        $context = AiInformationAccess::context($manager);
        $fingerprint = app(ManagerInvitationService::class)->fingerprint($manager);
        $manager->job_trade = '덕트';
        $this->assertNotSame($context, AiInformationAccess::context($manager));
        $this->assertNotSame($fingerprint, app(ManagerInvitationService::class)->fingerprint($manager));
    }

    public function test_foreman_can_submit_own_receipt_and_purchase_request_without_finance_or_buyer_access(): void
    {
        [$company, $site, $second, $pipe] = $this->fixtures();
        Queue::fake();
        Storage::fake('public');
        $this->mock(GeminiReceiptAnalyzer::class, fn ($mock) => $mock->shouldReceive('analyze')->once()->andReturn(['amount' => 25, 'date' => '2026-10-06', 'vendor_name' => 'Supply store']));
        $foreman = $this->user($company, [$site], 'trade_lead', $pipe);
        $this->actingAsPurchaseUser($foreman);
        $this->get('/attendance-app/workspace')->assertOk()->assertSee('작업반장 · 관리업무')->assertSee('영수증 등록')->assertSee('구매신청');
        $this->post('/expense-app/submit', ['receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nreceipt\n%%EOF"), 'payment_type' => 'personal', 'amount' => 25], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('mobile_expenses', ['employee_id' => $foreman->employee_id, 'status' => 'pending', 'amount' => 25]);
        $this->assertDatabaseHas('intelligent_documents', ['source' => 'integrated', 'document_type' => 'receipt']);
        $this->getJson('/expense-app/list')->assertOk()->assertJsonCount(1, 'items');
        $request = $this->postJson('/purchase-requests', ['site_id' => $site->id, 'request_key' => (string) Str::uuid(), 'lines' => [['name' => 'Pipe', 'quantity' => 2, 'unit' => 'EA']]])
            ->assertOk()->assertJsonPath('success', true)->json('request');
        $this->assertDatabaseHas('purchase_requests', ['id' => $request['id'], 'requested_by_id' => $foreman->id, 'approval_status' => 'pending']);
        $this->postJson('/purchase-requests/'.$request['id'].'/action', ['action' => 'order', 'version' => $request['version']])->assertForbidden();
        $this->postJson('/purchase-requests', ['site_id' => $second->id, 'request_key' => (string) Str::uuid(), 'lines' => [['name' => 'Pipe']]])->assertForbidden();
        $this->getJson('/purchase-requests?desk=1')->assertForbidden();
        $this->postJson('/smart-company-api/api_getExpenses', ['args' => []])->assertForbidden();
    }

    public function test_trade_invitation_preserves_scope_and_existing_invites_without_new_field_still_work(): void
    {
        [$company, $site, , $pipe] = $this->fixtures();
        $root = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($root);
        $service = app(ManagerInvitationService::class);
        $input = ['kind' => 'new_employee', 'companyId' => $company->id, 'siteId' => $site->id, 'siteIds' => [$site->id]];
        $result = $service->issue($input + ['jobRole' => 'trade_manager', 'scope' => 'trade', 'jobTrade' => '배관']);
        $this->assertTrue($result['success']);
        $invite = ManagerInvitation::latest('id')->first();
        $this->assertSame('배관', $invite->grant['job_trade']);
        $this->get($result['url'])->assertOk()->assertSee('공정팀장');
        $this->post($result['url'].'/verify', ['name' => 'Pipe manager', 'phone' => '2025559876'])->assertRedirect();
        $this->post($result['url'].'/complete', ['email' => 'pipe-manager@example.test', 'password' => 'PipeManager2026!', 'password_confirmation' => 'PipeManager2026!'])->assertRedirect();
        $account = User::where('email', 'pipe-manager@example.test')->firstOrFail();
        $this->assertSame('trade_manager', $account->job_role);
        $this->assertSame('trade', $account->access_scope);
        $this->assertSame('배관', $account->job_trade);
        $this->assertSame('trade_manager', $account->employee->position);
        $this->actingAsPurchaseUser($root);
        $old = $service->issue($input + ['jobRole' => 'trade_lead', 'scope' => 'team', 'teamId' => $pipe->id]);
        $invite = ManagerInvitation::latest('id')->first();
        $grant = $invite->grant;
        unset($grant['job_trade']);
        $invite->update(['grant' => $grant]);
        $this->get($old['url'])->assertOk();
    }
}
