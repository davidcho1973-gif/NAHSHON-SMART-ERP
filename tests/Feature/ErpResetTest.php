<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 시험 데이터를 비우고 처음부터 다시 시작하는 명령.
 *
 * 이 명령이 위험한 이유는 실수로 도는 것이 아니라 <b>말한 대로 안 지우는 것</b>이다.
 * 처음 짤 때 TRUNCATE ... CASCADE 를 썼더니 현장이 통째로 사라졌다 — sites 가
 * employees 를 참조하고 있어서, employees 를 비우면 CASCADE 가 sites 까지 따라갔다.
 * "현장은 남습니다" 라고 말해 놓고 사라지는 것이 가장 나쁜 결과다.
 *
 * 그래서 여기서는 "지워졌는가" 만큼 "남았는가" 를 본다.
 */
class ErpResetTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'SUB', 'name' => '협력사', 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '테스트 작업자', 'employment_status' => 'active',
        ]);
        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'company_id' => $company->id, 'event_type' => 'clock_in',
            'event_at' => Carbon::now(), 'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'approved', 'source' => 'web_portal',
        ]);
        User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);
        User::factory()->create([
            'email' => 'boss@example.com', 'access_role' => 'super_admin',
            'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    // ── 미리보기가 기본이다 ─────────────────────────────────────────────

    public function test_it_changes_nothing_without_force(): void
    {
        // 지우는 명령이 기본으로 지우면, 한 번 잘못 친 사람은 되돌릴 수 없다.
        $this->artisan('erp:reset')->assertSuccessful();

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_the_preview_says_it_did_nothing(): void
    {
        $this->artisan('erp:reset')
            ->expectsOutputToContain('아무것도 지우지 않았습니다')
            ->assertSuccessful();
    }

    // ── 업무 데이터만 지운다 ────────────────────────────────────────────

    public function test_it_clears_the_work_records(): void
    {
        $this->artisan('erp:reset --force')->assertSuccessful();

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, AttendanceLog::withTrashed()->count());
    }

    public function test_it_keeps_the_sites(): void
    {
        // 여기가 처음에 깨졌던 자리다. 현장을 다시 만들지 않아도 되는 것이
        // 이 모드를 쓰는 이유 전부다.
        $this->artisan('erp:reset --force')->assertSuccessful();

        $this->assertSame(1, Site::count());
        $this->assertSame('S1', Site::first()->code);
    }

    public function test_the_administrator_can_still_log_in(): void
    {
        // 다 지운 뒤 아무도 못 들어오면 그 배포는 화면으로는 되살릴 방법이 없다.
        $this->artisan('erp:reset --force')->assertSuccessful();

        $admin = User::query()->where('email', 'boss@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('super_admin', $admin->access_role);
    }

    public function test_worker_accounts_go_with_their_employees(): void
    {
        // 직원을 지우면서 그 사람 계정을 남기면, 아무에게도 연결되지 않은 계정이
        // 앱에 로그인한 채로 남는다.
        $this->artisan('erp:reset --force')->assertSuccessful();

        $this->assertSame(0, User::query()->where('access_role', 'worker')->count());
    }

    public function test_the_own_company_survives_so_registration_still_works(): void
    {
        // 자사 회사가 없으면 직원 등록에서 소속 회사를 고를 수 없다.
        $this->artisan('erp:reset --force')->assertSuccessful();

        $this->assertNotNull(Company::query()->where('company_type', Company::TYPE_OWN)->first());
    }

    // ── 전부 지우기 ─────────────────────────────────────────────────────

    public function test_all_wipes_the_sites_too(): void
    {
        $this->artisan('erp:reset --all --force')->assertSuccessful();

        $this->assertSame(0, Site::count());
        $this->assertSame(0, Employee::count());
    }

    public function test_all_still_leaves_a_way_back_in(): void
    {
        $this->artisan('erp:reset --all --force')->assertSuccessful();

        $this->assertGreaterThan(0, User::query()->where('access_role', 'super_admin')->count());
        $this->assertNotNull(Company::query()->where('company_type', Company::TYPE_OWN)->first());
    }

    // ── 새 관리자 ───────────────────────────────────────────────────────

    public function test_it_can_hand_the_keys_to_someone_new(): void
    {
        $this->artisan('erp:reset --force --admin=new@example.com')->assertSuccessful();

        $this->assertSame(
            'super_admin',
            User::query()->where('email', 'new@example.com')->value('access_role')
        );
    }

    // ── 운영 환경 ───────────────────────────────────────────────────────

    public function test_it_refuses_to_run_in_production(): void
    {
        // 운영에서 이 명령이 도는 것은 사고이지 작업이 아니다.
        app()['env'] = 'production';

        $this->artisan('erp:reset --force')
            ->expectsOutputToContain('운영 환경입니다')
            ->assertFailed();

        $this->assertSame(1, Employee::count());
    }
}
