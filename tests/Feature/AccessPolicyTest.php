<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Support\AccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 협력사 관리자는 자기 회사 울타리를 넘지 못한다.
 *
 * 협력사 소장에게 계정을 주면 자기 인원을 스스로 관리할 수 있어 편하다. 그런데 남의
 * 회사 인건비·인원 명단이 함께 보이면 그것만으로 사고다 — 우리가 관리하는 다른
 * 협력사의 단가가 경쟁사에게 새는 셈이 된다.
 *
 * 예전 규칙은 <b>access_scope 가 'all_sites' 이면 무조건 통과</b>였다. 범위를 실수로
 * 넓게 주는 순간 울타리가 사라졌다. 그래서 여기서는 <b>역할이 범위를 이긴다</b> —
 * 협력사 관리자는 범위를 아무리 넓게 줘도 자기 회사 밖을 못 본다.
 */
class AccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $ours;
    private Company $partner;
    private Company $rival;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ours = Company::create(['code' => 'OWNCO', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->partner = Company::create(['code' => 'PARTNER', 'name' => '협력사 A', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);
        $this->rival = Company::create(['code' => 'RIVAL', 'name' => '협력사 B', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);
        $this->site = Site::create(['company_id' => $this->ours->id, 'code' => 'SITE-1', 'name' => '현장', 'status' => 'active']);
    }

    private function employee(Company $company, string $name): Employee
    {
        return Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'first_name' => $name, 'last_name' => 'Kim',
            'email' => strtolower($name).'@example.com', 'employment_status' => 'active',
        ]);
    }

    private function user(Employee $employee, string $role, string $scope = 'self'): User
    {
        return User::factory()->create([
            'employee_id' => $employee->id, 'email' => $employee->email,
            'access_role' => $role, 'access_scope' => $scope, 'account_status' => 'active',
        ]);
    }

    private function expense(Company $company, float $amount): MobileExpense
    {
        return MobileExpense::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'employee_id' => $this->employee($company, 'E'.$amount)->id,
            'payment_type' => 'corporate', 'category' => '5201 Materials & Supplies',
            'description' => $company->code.' 지출', 'amount' => $amount,
            'expense_date' => now()->toDateString(), 'status' => 'pending',
        ]);
    }

    // ── 울타리 ─────────────────────────────────────────────────────────

    public function test_a_partner_manager_sees_only_their_own_company_money(): void
    {
        $this->expense($this->partner, 111);
        $this->expense($this->rival, 222);
        $this->expense($this->ours, 333);

        // 범위를 전체로 넓게 줘도 — 역할이 범위를 이긴다.
        $manager = $this->user($this->employee($this->partner, 'PM'), 'vendor_admin', 'all_sites');

        $rows = $this->actingAs($manager)->postJson('/smart-company-api/api_getExpenses', [
            'args' => [], 'siteId' => 'ALL',
        ])->assertOk()->json();

        $details = collect($rows)->pluck('detail');
        $this->assertTrue($details->contains('PARTNER 지출'));
        $this->assertFalse($details->contains('RIVAL 지출'), '협력사 관리자에게 다른 협력사의 지출이 보입니다.');
        $this->assertFalse($details->contains('OWNCO 지출'), '협력사 관리자에게 자사 지출이 보입니다.');
    }

    public function test_a_partner_manager_cannot_reach_other_companies_people(): void
    {
        $this->employee($this->partner, 'Mine');
        $this->employee($this->rival, 'Theirs');
        $manager = $this->user($this->employee($this->partner, 'PM2'), 'vendor_admin', 'all_sites');

        $names = app(CommunicationService::class)
            ->directCandidatesForUser($manager)
            ->pluck('first_name');

        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'), '다른 회사 사람 명단이 보입니다 — 명단 자체가 정보입니다.');
    }

    public function test_a_partner_manager_with_no_company_sees_nothing(): void
    {
        // 소속을 모르는 협력사 계정은 열어 두지 않는다 — 모르면 닫는 편이 안전하다.
        $orphan = User::factory()->create([
            'access_role' => 'vendor_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->assertSame(0, AccessPolicy::lockedCompanyId($orphan));
        $this->assertFalse(AccessPolicy::canSeeCompany($orphan, $this->partner->id));
    }

    public function test_our_own_managers_are_not_fenced_in(): void
    {
        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        $this->assertNull(AccessPolicy::lockedCompanyId($admin));
        $this->assertTrue(AccessPolicy::canSeeCompany($admin, $this->rival->id));
    }

    // ── 설계대로의 권한 ────────────────────────────────────────────────

    public function test_money_belongs_to_four_roles_only(): void
    {
        foreach (['super_admin', 'admin', 'hr_manager', 'payroll'] as $role) {
            $this->assertTrue(AccessPolicy::canManageMoney(User::factory()->make(['access_role' => $role])), $role);
        }

        foreach (['site_manager', 'safety_manager', 'foreman', 'vendor_admin', 'worker', 'client', 'viewer'] as $role) {
            $this->assertFalse(AccessPolicy::canManageMoney(User::factory()->make(['access_role' => $role])),
                "{$role} 이(가) 경비·급여를 만질 수 있습니다 — 현장과 회계는 분리해야 합니다.");
        }
    }

    public function test_hiring_and_paying_are_separated(): void
    {
        // 한 사람이 직원도 등록하고 급여도 지급하면 견제가 없다.
        $payroll = User::factory()->make(['access_role' => 'payroll']);

        $this->assertTrue(AccessPolicy::canManageMoney($payroll));
        $this->assertFalse(AccessPolicy::canManagePeople($payroll), '회계가 직원 등록까지 할 수 있습니다.');
    }

    public function test_only_two_roles_touch_accounts_and_permissions(): void
    {
        $this->assertTrue(AccessPolicy::canManageSystem(User::factory()->make(['access_role' => 'super_admin'])));
        $this->assertTrue(AccessPolicy::canManageSystem(User::factory()->make(['access_role' => 'admin'])));

        foreach (['hr_manager', 'payroll', 'site_manager'] as $role) {
            $this->assertFalse(AccessPolicy::canManageSystem(User::factory()->make(['access_role' => $role])), $role);
        }
    }

    public function test_the_site_manager_runs_the_site_but_not_the_money(): void
    {
        $siteManager = User::factory()->make(['access_role' => 'site_manager']);

        $this->assertTrue(AccessPolicy::canManageSite($siteManager));
        $this->assertTrue(AccessPolicy::canAnnounce($siteManager));
        $this->assertFalse(AccessPolicy::canManageMoney($siteManager));
    }

    public function test_the_safety_manager_can_announce_but_not_run_the_site_ledger(): void
    {
        $safety = User::factory()->make(['access_role' => 'safety_manager']);

        $this->assertTrue(AccessPolicy::canAnnounce($safety));
        $this->assertFalse(AccessPolicy::canManageSite($safety));
        $this->assertFalse(AccessPolicy::canManageMoney($safety));
    }

    public function test_outside_eyes_change_nothing(): void
    {
        foreach (['client', 'viewer'] as $role) {
            $outsider = User::factory()->make(['access_role' => $role]);

            $this->assertTrue($outsider->isReadOnly(), "{$role} 이(가) 데이터를 바꿀 수 있습니다.");
            $this->assertFalse(AccessPolicy::canManageMoney($outsider));
            $this->assertFalse(AccessPolicy::canManageSite($outsider));
            $this->assertFalse(AccessPolicy::canAnnounce($outsider));
        }
    }

    // ── 규칙이 한 곳에 있는가 ──────────────────────────────────────────

    public function test_the_money_rule_lives_in_one_place(): void
    {
        // 같은 역할 배열이 열한 군데에 복사돼 있었다. 새 역할을 만들 때 한 군데를
        // 빠뜨리면 그 화면만 조용히 다르게 동작한다.
        foreach ([
            'app/Services/Finance/ExpenseReviewService.php',
            'app/Http/Controllers/PayrollController.php',
            'app/Http/Controllers/ExpensePreApprovalController.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringContainsString('AccessPolicy', $source, "{$file} 이 공용 권한 규칙을 쓰지 않습니다.");
        }
    }
}
