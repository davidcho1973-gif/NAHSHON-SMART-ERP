<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpensePreApproval;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensePreApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'TEST-COMP',
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'TEST-SITE',
            'name' => 'Test Site',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'employment_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id,
            'email' => 'jane.smith@example.com',
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);
    }

    public function test_pre_approval_routes_require_auth(): void
    {
        $this->get(route('expense-pre-approval.index'))->assertRedirect(route('login'));
        $this->get(route('expense-pre-approval.create'))->assertRedirect(route('login'));
        $this->post(route('expense-pre-approval.store'))->assertRedirect(route('login'));
    }

    public function test_pre_approval_index_shows_requests_and_stat_counts(): void
    {
        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Project Kickoff Catering',
            'description' => 'Meals for 10 people',
            'justification' => 'Team team-building and kickoff meeting',
            'estimated_amount' => 150.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'draft',
        ]);

        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Office Whiteboard',
            'description' => 'Whiteboard for brain storming',
            'justification' => 'Needed for visual planning',
            'estimated_amount' => 75.50,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'corporate',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->get(route('expense-pre-approval.index'));

        $response->assertStatus(200);
        $response->assertViewHas('requests');
        $response->assertViewHas('draftCount', 1);
        $response->assertViewHas('pendingCount', 1);
        $response->assertSee('Project Kickoff Catering');
        $response->assertSee('Office Whiteboard');
    }

    public function test_pre_approval_create_renders_correctly(): void
    {
        $response = $this->actingAs($this->user)->get(route('expense-pre-approval.create'));

        $response->assertStatus(200);
        $response->assertViewHas('sites');
        $response->assertSee('Test Site');
    }

    public function test_pre_approval_store_can_save_as_draft(): void
    {
        $data = [
            'title' => 'Travel Lodging Phoenix',
            'description' => '3 nights hotel',
            'justification' => 'Onsite client visit',
            'estimated_amount' => '450.00',
            'planned_date' => '2026-07-01',
            'payment_method' => 'personal',
            'site_id' => $this->site->id,
            'action' => 'draft',
        ];

        $response = $this->actingAs($this->user)->post(route('expense-pre-approval.store'), $data);

        $response->assertRedirect(route('expense-pre-approval.index'));
        $response->assertSessionHas('success', 'Pre-approval draft saved.');

        $this->assertDatabaseHas('expense_pre_approvals', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Travel Lodging Phoenix',
            'status' => 'draft',
        ]);
    }

    public function test_pre_approval_store_can_submit_for_approval(): void
    {
        $data = [
            'title' => 'Client Lunch Meeting',
            'description' => 'Lunch with ACME corp representatives',
            'justification' => 'Quarterly review',
            'estimated_amount' => '120.00',
            'planned_date' => '2026-06-25',
            'payment_method' => 'corporate',
            'site_id' => $this->site->id,
            'action' => 'save',
        ];

        $response = $this->actingAs($this->user)->post(route('expense-pre-approval.store'), $data);

        $response->assertRedirect(route('expense-pre-approval.index'));
        $response->assertSessionHas('success', 'Pre-approval request submitted.');

        $this->assertDatabaseHas('expense_pre_approvals', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Client Lunch Meeting',
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_view_all_pre_approval_requests(): void
    {
        $otherEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Alex',
            'last_name' => 'Worker',
            'email' => 'alex.worker@example.com',
            'employment_status' => 'active',
        ]);

        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Jane request',
            'description' => 'First request',
            'justification' => 'Needed for field work',
            'estimated_amount' => 100.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $otherEmployee->id,
            'title' => 'Alex request',
            'description' => 'Second request',
            'justification' => 'Needed for field work',
            'estimated_amount' => 200.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'corporate',
            'status' => 'pending',
        ]);

        $admin = User::factory()->create([
            'access_role' => 'payroll',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('expense-pre-approval.index'));

        $response->assertOk();
        $response->assertSee('Jane request');
        $response->assertSee('Alex request');
        $response->assertViewHas('pendingCount', 2);
    }

    public function test_admin_can_approve_and_reject_pre_approval_requests(): void
    {
        $approveRequest = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Approve me',
            'description' => 'Ready',
            'justification' => 'Needed for field work',
            'estimated_amount' => 100.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        $rejectRequest = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Reject me',
            'description' => 'Not ready',
            'justification' => 'Insufficient detail',
            'estimated_amount' => 200.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('expense-pre-approval.approve', $approveRequest))
            ->assertRedirect(route('expense-pre-approval.index'));

        $this->actingAs($admin)
            ->patch(route('expense-pre-approval.reject', $rejectRequest))
            ->assertRedirect(route('expense-pre-approval.index'));

        $this->assertDatabaseHas('expense_pre_approvals', [
            'id' => $approveRequest->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('expense_pre_approvals', [
            'id' => $rejectRequest->id,
            'status' => 'rejected',
        ]);
    }

    public function test_worker_cannot_approve_pre_approval_requests(): void
    {
        $request = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Worker cannot approve',
            'description' => 'Pending request',
            'justification' => 'Needs approval',
            'estimated_amount' => 100.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->patch(route('expense-pre-approval.approve', $request))
            ->assertForbidden();

        $this->assertDatabaseHas('expense_pre_approvals', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }
}
