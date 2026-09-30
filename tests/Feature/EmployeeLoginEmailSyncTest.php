<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 직원 이메일과 로그인 계정 이메일이 어긋나는 문제.
 *
 * 두 칸이 따로 있다. 직원 정보의 이메일은 연락처고, 로그인 계정의 이메일은 구글
 * 로그인에 쓰는 신원이다. 그런데 <b>인원관리 수정 화면에는 "이메일" 한 칸만 보인다.</b>
 * 그래서 그것을 고치면 로그인도 따라간다고 믿게 된다.
 *
 * 실제로 어긋났다 — 직원 이메일을 고쳤는데 링크 보내기 화면은 옛 계정을 계속 보여 줬고,
 * 그 링크를 받은 작업자는 자기 것이 아닌 주소로 로그인하라는 안내를 받았다.
 * 두 화면 다 자기 값은 맞게 보여 주고 있어서 어느 쪽도 틀려 보이지 않았다.
 *
 * 그렇다고 조용히 같이 바꾸지도 않는다. 로그인 주소를 바꾸는 것은 "이 사람이 누구인가"
 * 를 바꾸는 일이라, 잘못 바꾸면 그 사람은 앱에 못 들어온다. 물어보고, 예라고 한
 * 경우에만 옮긴다.
 */
class EmployeeLoginEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'TEST CO', 'status' => 'active']);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'name' => 'Cristian rosas',
            'email' => 'old@gmail.com', 'employment_status' => 'active',
        ]);
        $this->account = User::factory()->create([
            'employee_id' => $this->employee->id, 'email' => 'old@gmail.com',
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAsPurchaseUser(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
    }

    private function save(array $over = []): array
    {
        return app(EmployeeAdminService::class)->save(array_merge([
            'id' => $this->employee->id,
            'name' => $this->employee->name,
            'companyId' => (string) $this->company->id,
            'employmentType' => 'direct',
            'status' => 'active',
        ], $over));
    }

    public function test_changing_the_employee_email_alone_leaves_the_login_untouched(): void
    {
        // 로그인 주소를 조용히 바꾸면, 잘못 바꿨을 때 그 사람은 앱에 못 들어온다.
        $res = $this->save(['email' => 'new@gmail.com']);

        $this->assertTrue($res['success']);
        $this->assertSame('new@gmail.com', $this->employee->fresh()->email);
        $this->assertSame('old@gmail.com', $this->account->fresh()->email);
    }

    public function test_it_reports_the_mismatch_so_the_screen_can_say_so(): void
    {
        // 말해 주지 않으면 반장은 방금 고쳐 놓고 옛 주소를 보게 되는데,
        // 어느 화면도 틀려 보이지 않아 원인을 못 찾는다.
        $res = $this->save(['email' => 'new@gmail.com']);

        $this->assertTrue($res['emailMismatch']);
        $this->assertSame('old@gmail.com', $res['loginEmail']);
    }

    public function test_saying_yes_moves_the_login_too(): void
    {
        $res = $this->save(['email' => 'new@gmail.com', 'syncAccountEmail' => true]);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['accountEmailChanged']);
        $this->assertSame('new@gmail.com', $this->account->fresh()->email);
    }

    public function test_it_refuses_to_move_the_login_onto_someone_elses(): void
    {
        // 두 사람이 같은 주소로 로그인하면 누가 찍은 출퇴근인지 구별할 수 없다.
        User::factory()->create(['email' => 'taken@gmail.com']);

        $res = $this->save(['email' => 'taken@gmail.com', 'syncAccountEmail' => true]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('email', $res['errors']);
        $this->assertSame('old@gmail.com', $this->account->fresh()->email);
    }

    public function test_an_employee_without_an_account_saves_normally(): void
    {
        $solo = Employee::create([
            'company_id' => $this->company->id, 'name' => '계정 없음',
            'employment_status' => 'active',
        ]);

        $res = app(EmployeeAdminService::class)->save([
            'id' => $solo->id, 'name' => $solo->name,
            'companyId' => (string) $this->company->id,
            'employmentType' => 'direct', 'status' => 'active',
            'email' => 'solo@gmail.com', 'syncAccountEmail' => true,
        ]);

        $this->assertTrue($res['success']);
        $this->assertArrayNotHasKey('loginEmail', $res);
    }

    public function test_the_list_carries_the_login_email(): void
    {
        $row = collect(app(EmployeeAdminService::class)->list()['rows'])
            ->firstWhere('id', $this->employee->id);

        $this->assertSame('old@gmail.com', $row['loginEmail']);
    }

    public function test_the_send_screen_points_out_the_mismatch(): void
    {
        $this->employee->update(['email' => 'new@gmail.com']);

        $res = $this->get(route('attendance-app.employee.share', ['employee' => $this->employee]))
            ->assertOk();

        $res->assertSee('old@gmail.com');                 // 로그인은 이것
        $res->assertSee('new@gmail.com');                 // 직원 정보는 이것
        $res->assertSee('위 계정과 다릅니다', false);
    }

    public function test_the_send_screen_stays_quiet_when_they_match(): void
    {
        // 맞는데도 경고가 뜨면 사람들은 경고를 무시하게 된다.
        $this->get(route('attendance-app.employee.share', ['employee' => $this->employee]))
            ->assertOk()->assertDontSee('위 계정과 다릅니다', false);
    }

    public function test_the_form_tells_the_manager_where_the_login_lives(): void
    {
        $js = file_get_contents(public_path('js/admin-employees.js'));

        $this->assertStringContainsString('loginHint', $js);
        $this->assertStringContainsString('syncAccountEmail', $js);
    }

    public function test_lower_administrators_cannot_take_over_a_purchase_account_via_employee_email_sync(): void
    {
        foreach (['admin', 'hr_manager'] as $role) {
            $this->actingAsPurchaseUser(User::factory()->create([
                'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
            ]));
            foreach (['purchase_request_enabled', 'purchase_buy_enabled'] as $grant) {
                $this->account->forceFill(['purchase_request_enabled' => false, 'purchase_buy_enabled' => false,
                    $grant => true, 'access_role' => 'admin', 'account_status' => 'suspended'])->save();
                $before = $this->employee->fresh()->getAttributes();

                $result = $this->save(['email' => 'takeover@example.com', 'syncAccountEmail' => true, 'name' => 'Replacement person']);

                $this->assertFalse($result['success']);
                $this->assertStringContainsString('수퍼관리자', $result['error']);
                $this->assertSame($before, $this->employee->fresh()->getAttributes(), 'Rejected identity changes must not partially save employee data.');
                $this->assertSame('old@gmail.com', $this->account->fresh()->email);
            }
        }
    }

    public function test_existing_contact_email_mismatch_cannot_be_used_to_transfer_a_granted_login(): void
    {
        $this->account->forceFill(['purchase_request_enabled' => true])->save();
        $this->employee->update(['email' => 'different@example.com']);

        $result = $this->save(['email' => 'different@example.com', 'syncAccountEmail' => true]);

        $this->assertFalse($result['success']);
        $this->assertSame('old@gmail.com', $this->account->fresh()->email);
        $this->assertSame('different@example.com', $this->employee->fresh()->email);
    }

    public function test_employee_identity_and_fallback_scope_changes_cannot_expand_purchase_access(): void
    {
        $otherCompany = Company::create(['code' => 'C2', 'name' => 'Other company', 'status' => 'active']);
        $site = Site::create(['company_id' => $this->company->id, 'code' => 'C1-S', 'name' => 'Other site', 'status' => 'active']);
        $this->account->forceFill(['purchase_buy_enabled' => true, 'access_role' => 'admin', 'access_scope' => 'site'])->save();
        $before = $this->employee->fresh()->getAttributes();

        foreach ([['companyId' => $otherCompany->id], ['siteId' => $site->id], ['phone' => '9125550101'],
            ['employeeNumber' => 'NEW-ID'], ['badgeNumber' => 'NEW-NFC'], ['qrRole' => 'attendance_admin', 'qrScope' => 'site']] as $change) {
            $result = $this->save(['email' => 'old@gmail.com'] + $change);
            $this->assertFalse($result['success']);
            $this->assertSame($before, $this->employee->fresh()->getAttributes());
        }
    }

    public function test_hr_can_edit_ordinary_details_and_suspend_but_cannot_reactivate_or_delete_purchase_identity(): void
    {
        $this->account->forceFill(['purchase_request_enabled' => true])->save();
        $result = $this->save(['email' => 'old@gmail.com', 'nationality' => 'US']);
        $this->assertTrue($result['success']);
        $this->assertSame('US', $this->employee->fresh()->nationality);

        $service = app(EmployeeAdminService::class);
        $number = $this->employee->fresh()->employee_number;
        $this->assertTrue($service->setStatus($number, 'terminated')['success']);
        $this->assertFalse($service->setStatus($number, 'active')['success']);
        $this->assertFalse($this->save(['email' => 'old@gmail.com', 'status' => 'active'])['success']);
        $this->assertFalse($service->delete($this->employee->id)['success']);
        $this->assertSame('terminated', $this->employee->fresh()->employment_status);
        $this->assertNotNull($this->account->fresh()->employee_id);
    }

    public function test_only_a_formal_superadministrator_session_can_update_a_granted_employee_identity(): void
    {
        $this->account->forceFill(['purchase_buy_enabled' => true])->save();
        $this->actingAsPurchaseUser(User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
        request()->setLaravelSession(app('session.store'));
        session()->put(WorkerDeviceSession::FLAG, true);

        $this->assertFalse($this->save(['email' => 'new@example.com', 'syncAccountEmail' => true])['success']);
        $this->assertSame('old@gmail.com', $this->employee->fresh()->email);

        session()->forget(WorkerDeviceSession::FLAG);
        $this->assertTrue($this->save(['email' => 'new@example.com', 'syncAccountEmail' => true])['success']);
        $this->assertSame('new@example.com', $this->account->fresh()->email);
    }

    public function test_superadministrator_employee_identity_is_protected_without_explicit_purchase_flags(): void
    {
        $this->account->forceFill(['access_role' => 'super_admin'])->save();

        $this->assertFalse($this->save(['email' => 'takeover@example.com', 'syncAccountEmail' => true])['success']);
        $this->assertSame('old@gmail.com', $this->account->fresh()->email);
    }
}
