<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 직책별 입력 요건은 공용 직원 등록과 예전 링크에서 동일하다.
 *
 * 관리자는 이메일이 있어야 하고(로그인·서신이 그 주소로 간다) 어떤 자리인지가 정해져야
 * 한다. 반면 공종은 관리자에게도 있다 — 공정별 팀장이 곧 관리자다. 그리고 QR 은
 * 촬영·복사되므로 스캔만으로 ERP 권한이 생기면 안 된다: 등록은 즉시, 권한은 승인 뒤.
 */
class ManagerJoinTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $own = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create([
            'company_id' => $own->id, 'code' => 'LG_ESS_PH', 'name' => 'LG ESS Phoenix',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('manager-join.store', ['site' => $this->site]), array_merge([
            'full_name' => '김반장',
            'company_name' => 'ABC ENG',
            'role' => 'Piping',
            'position' => 'foreman',
            'email' => 'foreman@example.com',
            'phone' => '480-555-0111',
            'preferred_language' => 'ko',
        ], $overrides));
    }

    public function test_a_trade_foreman_registers_as_staff_and_keeps_their_trade(): void
    {
        $this->submit()->assertOk();

        $employee = Employee::query()->where('name', '김반장')->firstOrFail();
        // 관리직으로 들어간다 — 출퇴근은 출석 확인, 시급 정밀 정산이 아니다.
        $this->assertSame(Employee::TYPE_STAFF, $employee->employment_type);
        $this->assertSame('presence', $employee->attendancePolicy());
        // 공종은 그대로 남는다 — 공정별 팀장도 자기 전공이 있다.
        $this->assertSame('Piping', $employee->role);
        $this->assertSame('foreman', $employee->position);
        $this->assertSame('foreman@example.com', $employee->email);
        $this->assertSame($this->site->id, $employee->site_id);
    }

    public function test_manager_registration_requires_email_and_position(): void
    {
        $this->submit(['email' => ''])->assertSessionHasErrors('email');
        $this->submit(['position' => ''])->assertSessionHasErrors('position');
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_old_manager_link_accepts_a_worker_position_without_granting_access(): void
    {
        $this->submit(['position' => 'worker', 'email' => ''])->assertOk();
        $employee = Employee::sole();
        $this->assertSame('worker', $employee->position);
        $this->assertSame(Employee::TYPE_DIRECT, $employee->employment_type);
        $this->assertNull($employee->user);
    }

    public function test_registering_does_not_hand_out_erp_access_but_raises_an_alert(): void
    {
        $this->submit()->assertOk();

        $employee = Employee::query()->where('name', '김반장')->firstOrFail();
        // QR 은 벽에 붙은 종이라 촬영·복사된다. 이메일도 검증되지 않은 자유 입력이다 —
        // 스캔만으로 로그인 계정이 생기면 그 사진 한 장이 곧 열쇠가 된다.
        $this->assertNull($employee->user);
        $this->assertSame(0, User::query()->where('email', 'foreman@example.com')->count());

        $alert = UnifiedAlert::query()->where('event_type', 'manager_account_pending')->sole();
        $this->assertSame('HR', $alert->source_module);
        $this->assertStringContainsString('김반장', $alert->title);
        $this->assertStringContainsString('Piping', $alert->content);
    }

    public function test_the_worker_door_still_takes_workers_without_an_email(): void
    {
        $this->post(route('worker-join.store', ['site' => $this->site]), [
            'full_name' => 'Miguel Torres',
            'company_name' => 'Sun Valley Mechanical',
            'role' => 'Insulation',
            'phone' => '480-555-0100',
            'employment_type' => 'indirect',
        ])->assertOk();

        $worker = Employee::query()->where('name', 'Miguel Torres')->firstOrFail();
        $this->assertSame(Employee::TYPE_INDIRECT, $worker->employment_type);
        $this->assertNull($worker->email);
    }

    public function test_old_links_show_all_positions_and_submit_to_the_common_form(): void
    {
        $manager = $this->get(route('manager-join.form', ['site' => $this->site]))->assertOk()->getContent();
        $this->assertStringContainsString(route('employee-join.entry-store'), $manager);
        $this->assertStringContainsString('value="foreman"', $manager);
        $this->assertStringContainsString('value="worker"', $manager);

        $worker = $this->get(route('worker-join.form', ['site' => $this->site]))->assertOk()->getContent();
        $this->assertStringContainsString(route('employee-join.entry-store'), $worker);
        $this->assertStringContainsString('value="worker"', $worker);
    }

    public function test_finishing_registration_opens_the_full_employee_app(): void
    {
        foreach ([
            'manager' => fn () => $this->submit(),
            'worker' => fn () => $this->post(route('worker-join.store', ['site' => $this->site]), [
                'full_name' => 'Miguel Torres', 'company_name' => 'Sun Valley Mechanical',
                'role' => 'Insulation', 'phone' => '480-555-0100', 'employment_type' => 'indirect',
            ]),
        ] as $door => $submit) {
            $body = $submit()->assertOk()->getContent();
            $this->assertStringContainsString(
                'id="t-install" href="'.route('attendance-app.index').'"',
                $body,
                "{$door} 등록 완료 화면에 직원 앱 링크가 없습니다",
            );
            $this->assertStringNotContainsString('?install=1', $body);
            $this->assertStringNotContainsString('inst.hidden = true', $body);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_old_registration_install_links_redirect_to_the_employee_app(): void
    {
        $this->get(route('gate.show', ['site' => $this->site]).'?install=1')
            ->assertRedirect(route('attendance-app.index'));
        $this->get(route('attendance-app.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(route('attendance-app.index'), session('url.intended'));
        $this->get(route('gate.show', ['site' => $this->site]))->assertOk()
            ->assertSee('id="open-worker-app"', false)
            ->assertSee(route('attendance-app.index'), false);
    }

    public function test_old_qr_links_print_the_same_employee_registration_target(): void
    {
        $managerPoster = $this->get(route('manager-join.qr', ['site' => $this->site]))->assertOk();
        $managerPoster->assertSee('직원 간편 등록');
        $managerPoster->assertSee(route('employee-join.form', ['site' => $this->site]), false);

        $workerPoster = $this->get(route('worker-join.qr', ['site' => $this->site]))->assertOk();
        $workerPoster->assertSee(route('employee-join.form', ['site' => $this->site]), false);
        $workerPoster->assertDontSee(route('manager-join.form', ['site' => $this->site]), false);
    }
}
