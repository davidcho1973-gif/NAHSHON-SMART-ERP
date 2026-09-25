<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 문이 둘이다 — 그리고 그 둘은 서로의 자리를 넘지 않는다.
 *
 * <b>사장님 결정(2026-09-23):</b>
 *   · 작업자앱은 작업반장·관리자도 «본인 핸드폰 뒷자리 4자리» 만으로 들어온다.
 *   · 대신 ERP 본화면은 슈퍼관리자가 권한을 승인한 계정으로 정식 로그인해야 열린다.
 *
 * 앞의 결정만 지키면 벽의 QR 과 남의 뒷 4자리를 아는 사람이 회사 전체 자료 앞에 선다.
 * 뒤의 결정만 지키면 현장 사람이 자기 출퇴근도 못 본다. 둘이 같이 있어야 뜻이 산다.
 */
class FourDigitDoorTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'OWN', 'name' => 'ABC MEP', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site', 'company_id' => $this->company->id,
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function person(string $name, string $phone, ?string $role = null, string $position = 'worker'): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'phone' => $phone, 'position' => $position,
            'employment_status' => 'active',
        ]);

        if ($role !== null) {
            User::factory()->create([
                'name' => $name, 'employee_id' => $employee->id,
                'access_role' => $role, 'access_scope' => $role === 'worker' ? 'self' : 'all_sites',
                'account_status' => 'active',
            ]);
        }

        return $employee->fresh();
    }

    private function enterWithDigits(Employee $employee, string $last4): array
    {
        $found = $this->postJson(route('worker-app.find'), ['last4' => $last4])->assertOk()->json('workers');
        $this->assertContains($employee->id, array_column($found, 'id'), '뒷 4자리로 본인이 나와야 한다');

        return $this->postJson(route('worker-app.enter'), ['employee_id' => $employee->id])->assertOk()->json();
    }

    public function test_a_worker_enters_the_app_with_four_digits_and_nothing_else(): void
    {
        $worker = $this->person('이대웅', '480-555-0142', 'worker');

        $result = $this->enterWithDigits($worker, '0142');

        $this->assertAuthenticatedAs($worker->user);
        $this->assertSame(route('attendance-app.index'), $result['redirect']);
        $this->assertNotEmpty($result['device_token']);
        $this->assertNotNull(WorkerDevice::query()->sole()->identity_verified_at, '이 휴대폰을 기억해 다음부터는 묻지 않는다');
        $this->get(route('attendance-app.index'))->assertOk();
    }

    public function test_a_foreman_and_a_manager_use_the_same_four_digit_door(): void
    {
        foreach ([['반장', '480-555-0111', 'foreman', 'foreman'], ['소장', '480-555-0122', 'site_manager', 'superintendent']] as [$name, $phone, $role, $position]) {
            $person = $this->person($name, $phone, $role, $position);

            $this->enterWithDigits($person, substr(preg_replace('/\D/', '', $phone), -4));

            $this->assertAuthenticatedAs($person->user);
            $this->get(route('attendance-app.index'))->assertOk();
            $this->post(route('logout'));
        }
    }

    /** 이 문으로는 ERP 본화면이 열리지 않는다 — 권한이 관리자여도 마찬가지다. */
    public function test_the_four_digit_door_never_opens_the_erp_main_screen(): void
    {
        $manager = $this->person('오아현', '480-555-0133', 'admin', 'office');

        $this->enterWithDigits($manager, '0133');

        $this->assertAuthenticatedAs($manager->user);
        $this->get('/')->assertRedirect(route('attendance-app.index'));
        // 화면을 거치지 않고 창구를 직접 불러도 마찬가지다 — 권한이 필요한 자료는 안 나간다.
        $this->postJson(route('api.smart-company', ['method' => 'api_getKakaoReminders']), [])
            ->assertOk()->assertJsonPath('success', false);
    }

    /** 정식 로그인 + 승인된 권한이면 본화면이 열린다. */
    public function test_an_approved_account_signing_in_properly_opens_the_erp(): void
    {
        $manager = $this->person('오아현', '480-555-0133', 'admin', 'office');

        $this->actingAs($manager->user);

        $this->get('/')->assertOk();
    }

    /** 작업자·반장 역할은 «현장 인력» 이라는 뜻이지 ERP 승인이 아니다. */
    public function test_field_roles_do_not_reach_the_erp_even_after_a_full_login(): void
    {
        foreach (['worker', 'foreman'] as $i => $role) {
            $person = $this->person('현장'.$i, '480-555-02'.(10 + $i), $role);

            $this->actingAs($person->user);
            $this->get('/')->assertRedirect(route('attendance-app.index'));
            $this->post(route('logout'));
        }
    }

    public function test_four_digits_alone_never_reach_a_terminated_person(): void
    {
        $gone = $this->person('퇴사자', '480-555-0155', 'worker');
        $gone->update(['employment_status' => 'terminated']);

        $this->postJson(route('worker-app.find'), ['last4' => '0155'])->assertOk()->assertJsonCount(0, 'workers');
        $this->postJson(route('worker-app.enter'), ['employee_id' => $gone->id])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_fewer_than_four_digits_never_lists_anybody(): void
    {
        $this->person('이대웅', '480-555-0142', 'worker');

        foreach (['', '4', '42', '142'] as $partial) {
            $this->postJson(route('worker-app.find'), ['last4' => $partial])
                ->assertOk()->assertJsonCount(0, 'workers');
        }
    }
}
