<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnifiedAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 등록은 작업자 등록과 다른 문이다.
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
    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
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

    public function test_a_manager_cannot_register_themselves_as_a_plain_worker(): void
    {
        // 관리자 문으로 들어와 '작업자' 를 고르면 어느 쪽도 아닌 기록이 남는다.
        $this->submit(['position' => 'worker'])->assertSessionHasErrors('position');
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_registering_does_not_hand_out_erp_access_but_raises_an_alert(): void
    {
        $this->submit()->assertOk();

        $employee = Employee::query()->where('name', '김반장')->firstOrFail();
        // QR 은 벽에 붙은 종이라 촬영·복사된다. 이메일도 검증되지 않은 자유 입력이다 —
        // 스캔만으로 로그인 계정이 생기면 그 사진 한 장이 곧 열쇠가 된다.
        $this->assertNull($employee->user);
        $this->assertSame(0, \App\Models\User::query()->where('email', 'foreman@example.com')->count());

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

    public function test_the_two_forms_post_to_their_own_doors(): void
    {
        $manager = $this->get(route('manager-join.form', ['site' => $this->site]))->assertOk()->getContent();
        $this->assertStringContainsString(route('manager-join.store', ['site' => $this->site]), $manager);
        // 감독하는 자리만 고를 수 있다.
        $this->assertStringContainsString('value="foreman"', $manager);
        $this->assertStringNotContainsString('value="worker"', $manager);

        $worker = $this->get(route('worker-join.form', ['site' => $this->site]))->assertOk()->getContent();
        $this->assertStringContainsString(route('worker-join.store', ['site' => $this->site]), $worker);
        $this->assertStringContainsString('value="worker"', $worker);
    }

    public function test_each_door_has_its_own_printable_qr(): void
    {
        $managerPoster = $this->get(route('manager-join.qr', ['site' => $this->site]))->assertOk();
        $managerPoster->assertSee('관리자 등록');
        $managerPoster->assertSee(route('manager-join.form', ['site' => $this->site]), false);

        $workerPoster = $this->get(route('worker-join.qr', ['site' => $this->site]))->assertOk();
        $workerPoster->assertSee(route('worker-join.form', ['site' => $this->site]), false);
        $workerPoster->assertDontSee(route('manager-join.form', ['site' => $this->site]), false);
    }
}
