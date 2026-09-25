<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ERP 본화면에 누가 들어올지는 <b>사람이 정한다</b> — 그 자리가 계정·권한 관리다.
 *
 * 2026-09-23 부터 현장 인력은 전화번호 뒷 4자리로 작업자 앱에 들어온다. 그 편의가
 * 회사 전체 자료까지 열면 안 되므로 ERP 본화면은 «승인된 역할» 에게만 열린다.
 * 그 승인은 새 장치가 아니라 이미 있던 것이다 — 역할을 주는 일이 곧 승인이다.
 *
 * 이 시험이 지키는 것: 슈퍼관리자는 줄 수 있고, 준 순간 실제로 열리고, 뺀 순간 닫히고,
 * 아무나 자기 권한을 올릴 수는 없다.
 */
class SuperAdminGrantsErpAccessTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name, string $role): User
    {
        $company = Company::firstOrCreate(['code' => 'OWN'], ['name' => 'ABC MEP', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $site = Site::firstOrCreate(['code' => 'AZ-01'], ['name' => 'Arizona Site', 'company_id' => $company->id, 'status' => 'active']);
        $employee = Employee::create([
            'name' => $name, 'company_id' => $company->id, 'site_id' => $site->id,
            'phone' => '480-555-01'.random_int(10, 99), 'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'name' => $name, 'employee_id' => $employee->id,
            'access_role' => $role, 'access_scope' => 'self', 'account_status' => 'active',
        ]);
    }

    public function test_a_super_admin_opens_the_erp_for_someone_and_it_actually_opens(): void
    {
        $boss = $this->person('사장', 'super_admin');
        $worker = $this->person('김작업', 'worker');

        // 주기 전에는 닫혀 있다.
        $this->actingAs($worker)->get('/')->assertRedirect(route('attendance-app.index'));

        $this->actingAs($boss);
        $result = app(UserAccessService::class)->save([
            'id' => $worker->id, 'name' => $worker->name, 'email' => 'office@example.test',
            'role' => 'site_manager', 'scope' => 'site', 'siteId' => $worker->employee->site_id,
            'status' => 'active', 'employeeId' => $worker->employee_id,
        ]);
        $this->assertTrue($result['success'], json_encode($result, JSON_UNESCAPED_UNICODE));

        // 준 순간 열린다.
        $this->actingAs($worker->fresh())->get('/')->assertOk();
    }

    public function test_taking_the_role_back_closes_the_erp_again(): void
    {
        $boss = $this->person('사장', 'super_admin');
        $manager = $this->person('오관리', 'site_manager');
        $this->actingAs($manager)->get('/')->assertOk();

        $this->actingAs($boss);
        app(UserAccessService::class)->save([
            'id' => $manager->id, 'name' => $manager->name, 'email' => '',
            'role' => 'worker', 'scope' => 'self', 'status' => 'active',
            'employeeId' => $manager->employee_id,
        ]);

        $this->actingAs($manager->fresh())->get('/')->assertRedirect(route('attendance-app.index'));
    }

    /** 목록이 «누가 들어올 수 있는가» 를 말해 준다 — 안 보이면 아무도 관리할 수 없다. */
    public function test_the_list_says_who_may_enter_the_erp(): void
    {
        $boss = $this->person('사장', 'super_admin');
        $this->person('김작업', 'worker');
        $this->person('오관리', 'site_manager');

        $this->actingAs($boss);
        $rows = collect(app(UserAccessService::class)->list()['rows'] ?? [])->keyBy('name');

        $this->assertTrue($rows['사장']['erpAccess']);
        $this->assertTrue($rows['오관리']['erpAccess']);
        $this->assertFalse($rows['김작업']['erpAccess'], '작업자는 작업자 앱까지다');
    }

    /** 인사담당자는 자기보다 높은 권한을 줄 수 없다 — 그 길이 열리면 승인이 뜻을 잃는다. */
    public function test_nobody_can_promote_beyond_their_own_rank(): void
    {
        $hr = $this->person('인사', 'hr_manager');
        $worker = $this->person('김작업', 'worker');

        $this->actingAs($hr);
        $result = app(UserAccessService::class)->save([
            'id' => $worker->id, 'name' => $worker->name, 'email' => 'x@example.test',
            'role' => 'super_admin', 'scope' => 'all_sites', 'status' => 'active',
            'employeeId' => $worker->employee_id,
        ]);

        $this->assertFalse($result['success'] ?? false);
        $this->assertSame('worker', $worker->fresh()->access_role);
    }

    /** 현장 인력은 권한을 줘도 «뒷 4자리» 문으로 들어오면 ERP 가 닫혀 있다. */
    public function test_even_an_approved_person_must_sign_in_properly(): void
    {
        $manager = $this->person('오관리', 'site_manager');

        $this->postJson(route('worker-app.find'), ['last4' => substr(preg_replace('/\D/', '', $manager->employee->phone), -4)])
            ->assertOk();
        $this->postJson(route('worker-app.enter'), ['employee_id' => $manager->employee_id])->assertOk();

        $this->assertAuthenticatedAs($manager->fresh());
        $this->get('/')->assertRedirect(route('attendance-app.index'));
    }
}
