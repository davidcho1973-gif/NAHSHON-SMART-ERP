<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Installation cards are for approved managers and foremen; workers receive the site QR guidance. */
class WorkerAppInstallCardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'LG_ESS_PH', 'name' => 'LG ESS Phoenix',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function worker(string $name = 'Cristian rosas'): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'employment_status' => 'active', 'preferred_language' => 'es',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_the_card_prints_the_exact_google_account_to_sign_in_with(): void
    {
        $employee = $this->worker();
        User::factory()->create([
            'employee_id' => $employee->id, 'email' => 'cristian.rosas@gmail.com',
            'access_role' => 'foreman', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAsPersonalAppUser($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $employee]))
            ->assertOk()
            ->assertSee('cristian.rosas@gmail.com')
            ->assertSee('Cristian rosas');
    }

    public function test_the_card_says_plainly_when_the_worker_has_no_account_yet(): void
    {
        // An employee without an eligible account must not receive an app installation card.
        $employee = $this->worker('Dhairo Carmona');

        $this->actingAsPersonalAppUser($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $employee]))
            ->assertOk()
            ->assertViewIs('worker-app.access')
            ->assertSee(route('gate.show', $this->site), false)
            ->assertDontSee('data:image/svg+xml;base64,', false);
    }

    public function test_a_suspended_account_is_not_printed_as_usable(): void
    {
        $employee = $this->worker();
        User::factory()->create([
            'employee_id' => $employee->id, 'email' => 'gone@gmail.com',
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'suspended',
        ]);

        $this->actingAsPersonalAppUser($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $employee]))
            ->assertOk()
            ->assertDontSee('gone@gmail.com')
            ->assertViewIs('worker-app.access');
    }

    public function test_an_active_worker_account_gets_site_attendance_guidance_instead_of_an_install_card(): void
    {
        $employee = $this->worker();
        $employee->update(['position' => 'foreman']);
        User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active']);

        foreach (['attendance-app.employee.install-card', 'attendance-app.employee.share'] as $route) {
            $this->actingAsPersonalAppUser($this->admin())->get(route($route, $employee))
                ->assertOk()->assertViewIs('worker-app.access')
                ->assertSee(route('gate.show', $this->site), false)
                ->assertDontSee('data:image/svg+xml;base64,', false)
                ->assertDontSee('rel="manifest"', false);
        }
    }

    public function test_the_card_carries_all_three_languages(): void
    {
        $employee = $this->worker();

        User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'foreman', 'account_status' => 'active']);

        $res = $this->actingAsPersonalAppUser($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $employee]))
            ->assertOk();

        $res->assertSee('업무 개인앱');
        $res->assertSee('Personal Work App');
        $res->assertSee('App personal de trabajo', false);
    }

    public function test_the_card_points_at_the_worker_app_not_the_gate(): void
    {
        $employee = $this->worker();

        User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'foreman', 'account_status' => 'active']);

        $this->actingAsPersonalAppUser($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $employee]))
            ->assertOk()
            ->assertSee(route('attendance-app.index'), false);
    }

    public function test_another_worker_cannot_read_someone_elses_card(): void
    {
        // 카드에는 남의 이메일이 적혀 있다.
        $target = $this->worker();
        $other = $this->worker('Someone Else');
        $otherUser = User::factory()->create([
            'employee_id' => $other->id, 'access_role' => 'foreman',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAsPersonalAppUser($otherUser)
            ->get(route('attendance-app.employee.install-card', ['employee' => $target]))
            ->assertForbidden();
    }

    // ── 로그인 뒤에 어디로 떨어지는가 ──────────────────────────────

    public function test_a_worker_lands_on_the_site_qr_guidance_not_the_personal_app(): void
    {
        // Previously installed worker icons recover to attendance guidance, without a new app login.
        $employee = $this->worker();
        $user = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->assertSame('/app', $user->landingPath());
    }

    public function test_a_foreman_lands_on_the_worker_app_too(): void
    {
        $user = User::factory()->create([
            'access_role' => 'foreman', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->assertSame('/attendance-app', $user->landingPath());
    }

    public function test_office_staff_still_land_on_the_erp(): void
    {
        // 예전에는 없어진 /admin 을 가리키고 있었다.
        foreach (['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll'] as $role) {
            $user = User::factory()->create([
                'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
            ]);

            $this->assertSame('/', $user->landingPath(), "[{$role}] 이 없어진 화면으로 갑니다.");
        }
    }

    public function test_login_returns_to_the_screen_the_worker_was_trying_to_open(): void
    {
        // 이게 없으면 /attendance-app 을 열었다가 로그인 뒤 ERP 첫 화면에 떨어진다.
        $source = file_get_contents(app_path('Http/Controllers/GoogleAuthController.php'));

        // ERP 첫 화면을 못 박아 두면 작업자도 거기로 간다. 역할별 목적지만 써야 한다.
        $this->assertStringNotContainsString("route('smart-company.index')", $source);
        $this->assertStringContainsString('url.intended', $source);
        $this->assertStringContainsString('landingPath()', $source);
    }
}
