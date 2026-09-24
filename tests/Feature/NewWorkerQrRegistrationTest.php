<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewWorkerQrRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create([
            'code' => 'OWN', 'name' => 'Own Company', 'status' => 'active', 'company_type' => Company::TYPE_OWN,
        ]);
        $this->site = Site::create([
            'company_id' => $company->id, 'code' => '703K', 'name' => 'Savannah',
            'timezone' => 'America/New_York', 'status' => 'active',
        ]);
    }

    public function test_public_qr_form_asks_only_for_name_and_phone(): void
    {
        $this->followingRedirects()->get(route('worker-join.form', $this->site))
            ->assertOk()
            ->assertSee('name="full_name"', false)
            ->assertSee('name="phone"', false)
            ->assertDontSee('name="company_id"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="email"', false);
    }

    public function test_registration_creates_worker_binds_phone_and_continues_to_gate(): void
    {
        $response = $this->withHeader('User-Agent', 'New worker phone')->post(
            route('worker-join.store', $this->site),
            ['full_name' => '  Miguel   Torres ', 'phone' => '(480) 555-0100', 'preferred_language' => 'es', 'email' => 'injected@example.com', 'position' => 'manager'],
        );

        $employee = Employee::sole();
        $response->assertOk()
            ->assertSee('/auth/pin/setup/', false)
            ->assertSee('PIN 설정하고 출근하기')
            // 이 폰을 기억하는 규칙은 worker-device-remember.js 한 곳에 있다(등록 화면 둘이 같이 쓴다).
            // 화면이 그 파일을 싣고 부르는지를 본다 — 저장 코드가 화면 안에 박혀 있는지가 아니라.
            ->assertSee('js/worker-device-remember.js', false)
            ->assertSee('window.rememberWorkerDevice(', false);
        $this->assertSame('Miguel Torres', $employee->name);
        $this->assertSame($this->site->id, $employee->site_id);
        $this->assertSame('worker', $employee->position);
        $this->assertNull($employee->email);
        // 공정도 소속도 이 화면은 묻지 않는다 — 그러니 적지도 않는다.
        // '미지정' 은 공정처럼 생긴 글자라 공종별 인원 집계에 영원히 한 칸을 차지했다.
        $this->assertNull($employee->role);
        $this->assertNull($employee->company_id);
        $this->assertSame(Employee::TYPE_UNVERIFIED, $employee->employment_type);
        $this->assertTrue((bool) data_get($employee->payload, 'self_registered_pending_hr'));
        $this->assertSame($employee->id, MemberRegistration::sole()->employee_id);
        $this->assertSame($employee->id, WorkerDevice::sole()->employee_id);
        $this->assertSame('worker', $employee->user?->access_role);
        $this->assertDatabaseCount('auth_setup_tokens', 1);
        $this->assertDatabaseHas('unified_alerts', ['event_type' => 'worker_self_registration_review']);

        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Miguel Torres', 'phone' => '4805550100',
        ])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('worker_devices', 1);
        $this->assertDatabaseCount('auth_setup_tokens', 1);
    }

    public function test_hr_can_copy_a_signed_followup_link_and_worker_completes_details(): void
    {
        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Ana Lopez', 'phone' => '4805550111',
        ])->assertOk();

        $hr = User::factory()->create(['access_role' => 'hr_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($hr);
        $row = app(EmployeeAdminService::class)->list()['rows'][0];
        $this->assertTrue($row['hrReviewPending']);
        $this->assertNotEmpty($row['onboardingRequestUrl']);

        $this->get($row['onboardingRequestUrl'])->assertOk()->assertSee('Ana Lopez');
        $this->post($row['onboardingRequestUrl'], [
            'email' => 'ana@example.com',
            'address' => '100 Main St, Savannah, GA',
            'emergency_contact_name' => 'Luis Lopez',
            'emergency_contact_phone' => '4805550199',
        ])->assertOk()->assertSee('W-9')->assertSee('/w9/', false);

        $registration = MemberRegistration::sole();
        $this->assertSame('100 Main St, Savannah, GA', $registration->address);
        $this->assertSame('ana@example.com', Employee::sole()->email);
    }

    public function test_short_phone_is_rejected_and_inactive_site_is_not_public(): void
    {
        $this->post(route('worker-join.store', $this->site), ['full_name' => 'Short Number', 'phone' => '1234'])
            ->assertSessionHasErrors('phone');

        $this->site->update(['status' => 'inactive']);
        $this->get(route('worker-join.form', $this->site))->assertNotFound();
    }

    public function test_returning_worker_is_recognized_without_overwriting_verified_hr_fields(): void
    {
        $partner = Company::create([
            'code' => 'PARTNER', 'name' => 'Partner', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER,
        ]);
        $employee = Employee::create([
            'company_id' => $partner->id,
            'site_id' => $this->site->id,
            'name' => 'Existing Worker',
            'phone' => '480-555-0222',
            'role' => 'Verified Piping',
            'position' => 'worker',
            'employment_type' => Employee::TYPE_INDIRECT,
            'employment_status' => 'active',
        ]);

        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Existing Worker', 'phone' => '(480) 555-0222',
        ])->assertSessionHasErrors('phone');

        $employee->refresh();
        $this->assertSame($partner->id, $employee->company_id);
        $this->assertSame('Verified Piping', $employee->role);
        $this->assertSame(Employee::TYPE_INDIRECT, $employee->employment_type);
        $this->assertDatabaseCount('employees', 1);
    }
}
