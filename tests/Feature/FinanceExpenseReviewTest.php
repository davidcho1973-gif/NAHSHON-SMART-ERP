<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 승인은 목록에서 끝난다 — 재무 '비용 제출 내역' 의 원클릭 처리.
 *
 * 문서함에서 자동으로 넘어온 비용이 '승인대기' 로 목록에 앉아도, 승인하려면
 * 다른 화면으로 건너가야 했다. 여기서 재무 목록의 원클릭 승인/반려/지급이
 * 실제로 상태를 바꾸고, 권한 없는 사람은 못 바꾸고, 규칙이 영수증 목록의
 * review 와 같은 한 곳(ExpenseReviewService)에서 나오는지 지킨다.
 */
class FinanceExpenseReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'TEST-COMP', 'name' => 'Test Company', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'TEST-SITE', 'name' => 'Test Site', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'John', 'last_name' => 'Doe',
            'email' => 'john.doe@example.com', 'employment_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function pendingExpense(array $attributes = []): MobileExpense
    {
        return MobileExpense::create(array_merge([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'corporate',
            'category' => '5401 Equipment Rental',
            'description' => '[문서함] Sunbelt Rentals 장비 임대',
            'amount' => 3185.77,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ], $attributes));
    }

    private function review(User $user, int $expenseId, string $decision)
    {
        return $this->actingAs($user)->postJson('/smart-company-api/api_reviewExpense', [
            'args' => [$expenseId, $decision],
            'siteId' => 'ALL',
        ]);
    }

    // ── 목록에서 승인이 되는가 ─────────────────────────────────────────

    public function test_a_manager_approves_a_pending_expense_right_from_the_list(): void
    {
        $admin = $this->admin();
        $expense = $this->pendingExpense();

        $response = $this->review($admin, $expense->id, 'approved');

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $expense->refresh();
        $this->assertSame('approved', $expense->status);
        $this->assertSame($admin->id, $expense->reviewed_by_user_id);
        $this->assertNotNull($expense->reviewed_at);
        $this->assertNull($expense->paid_at, '승인만 했는데 지급까지 찍혔습니다.');
    }

    public function test_rejection_works_the_same_way(): void
    {
        $expense = $this->pendingExpense();

        $this->review($this->admin(), $expense->id, 'rejected')->assertOk();

        $this->assertSame('rejected', $expense->fresh()->status);
    }

    public function test_an_approved_expense_can_be_marked_paid_with_audit_fields(): void
    {
        $admin = $this->admin();
        $expense = $this->pendingExpense(['status' => 'approved']);

        $this->review($admin, $expense->id, 'paid')->assertOk();

        $expense->refresh();
        $this->assertSame('paid', $expense->status);
        $this->assertSame($admin->id, $expense->paid_by_user_id);
        $this->assertNotNull($expense->paid_at);
    }

    // ── 승인과 동시에 반영되는가 ───────────────────────────────────────

    public function test_the_approval_shows_up_in_finance_stats_immediately(): void
    {
        $admin = $this->admin();
        $expense = $this->pendingExpense(['amount' => 500]);

        $before = $this->actingAs($admin)->postJson('/smart-company-api/api_getFinanceStats', [
            'args' => [], 'siteId' => 'ALL',
        ])->json('approvedExpenseAmount');

        $this->review($admin, $expense->id, 'approved')->assertOk();

        $after = $this->actingAs($admin)->postJson('/smart-company-api/api_getFinanceStats', [
            'args' => [], 'siteId' => 'ALL',
        ])->json('approvedExpenseAmount');

        $this->assertEquals(500.0, $after - $before, '승인했는데 재무 통계에 바로 반영되지 않았습니다.');
    }

    public function test_the_expense_rows_tell_the_screen_who_may_review(): void
    {
        $this->pendingExpense();

        $adminRow = $this->actingAs($this->admin())->postJson('/smart-company-api/api_getExpenses', [
            'args' => [], 'siteId' => 'ALL',
        ])->json('0');
        $this->assertTrue($adminRow['canReview']);
        $this->assertSame('pending', $adminRow['status']);

        $worker = User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);
        $workerRow = $this->actingAs($worker)->postJson('/smart-company-api/api_getExpenses', [
            'args' => [], 'siteId' => 'ALL',
        ])->json('0');
        $this->assertFalse($workerRow['canReview'], '승인 권한 없는 사람에게 승인 버튼이 갑니다.');
    }

    // ── 권한과 경계 ────────────────────────────────────────────────────

    public function test_a_worker_cannot_approve_even_their_own_expense(): void
    {
        $worker = User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);
        $expense = $this->pendingExpense();

        $response = $this->review($worker, $expense->id, 'approved');

        $response->assertOk();
        $this->assertFalse($response->json('success'));
        $this->assertSame('pending', $expense->fresh()->status, '권한 없는 승인이 통과했습니다.');
    }

    public function test_unknown_expense_or_decision_fails_cleanly(): void
    {
        $admin = $this->admin();

        $this->assertFalse($this->review($admin, 999999, 'approved')->json('success'));

        $expense = $this->pendingExpense();
        $this->assertFalse($this->review($admin, $expense->id, 'shredded')->json('success'));
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_the_list_and_the_receipt_screen_share_one_rule(): void
    {
        // 규칙이 두 벌이 되는 순간 한쪽만 고쳐진다 — 컨트롤러의 review 도
        // 같은 서비스에서 규칙을 가져와야 한다.
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/MobileExpenseController.php'));
        $this->assertStringContainsString('ExpenseReviewService', $controller,
            '영수증 목록의 원클릭 승인이 공용 규칙(ExpenseReviewService)을 쓰지 않습니다.');

        $data = (string) file_get_contents(base_path('app/Support/SmartCompanyData.php'));
        $this->assertStringContainsString('ExpenseReviewService', $data,
            '재무 목록의 원클릭 승인이 공용 규칙(ExpenseReviewService)을 쓰지 않습니다.');
    }
}
