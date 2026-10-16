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
            ->assertSee('현장 운영회사')
            ->assertSee('Own Company')
            ->assertSee('id="registration-review" hidden', false)
            ->assertDontSee('name="company_id"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="email"', false);
    }

    public function test_registration_confirms_worker_and_offers_installation_from_the_permanent_app(): void
    {
        $response = $this->withHeader('User-Agent', 'New worker phone')->post(
            route('worker-join.store', $this->site),
            [
                'full_name' => '  Miguel   Torres ', 'phone' => '(480) 555-0100', 'preferred_language' => 'es',
                'email' => 'injected@example.com', 'position' => 'manager', 'access_role' => 'super_admin',
                'access_scope' => 'all_sites', 'company_id' => $this->site->company_id, 'employment_type' => Employee::TYPE_DIRECT,
            ],
        );

        $employee = Employee::sole();
        $response->assertOk()
            // 첫 출근 앞에 PIN 화면을 두지 않는다 — 등록이 이 휴대폰을 이미 연결했다.
            ->assertDontSee('/auth/pin/setup/', false)
            ->assertSee('등록되었습니다')
            ->assertSee('개인앱 열고 설치')
            ->assertSee('href="'.route('attendance-app.index', ['install' => 1]).'"', false)
            ->assertSee('href="'.route('worker-app.manifest').'"', false)
            ->assertDontSee('window.setTimeout(', false)
            ->assertSee('출근 화면 열기')
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
        $this->assertNotNull(WorkerDevice::sole()->identity_verified_at, '연결된 휴대폰이어야 그 자리에서 찍을 수 있다');
        $this->assertSame('worker', $employee->user?->access_role);
        $this->assertSame('self', $employee->user?->access_scope);
        $this->assertSame(route('attendance-app.index', ['install' => 1]), $response->viewData('installUrl'));
        // 등록 완료는 출근 완료가 아니다. 실제 출근 버튼을 누르기 전에는 근태를 쓰지 않는다.
        $this->assertDatabaseCount('attendance_logs', 0);
        // 15분짜리 링크는 첫 출근의 길목에서 사라졌다. PIN 은 나중에 본인이 정한다.
        $this->assertDatabaseCount('auth_setup_tokens', 0);
        $this->assertDatabaseHas('unified_alerts', ['event_type' => 'worker_self_registration_review']);

        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Miguel Torres', 'phone' => '4805550100',
        ])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('worker_devices', 1);
        // 거부된 등록은 아무것도 남기지 않는다 — 휴대폰도, 설정 링크도.
        $this->assertDatabaseCount('auth_setup_tokens', 0);
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

    public function test_existing_account_cannot_be_claimed_by_reusing_its_name_or_phone(): void
    {
        $employee = Employee::create([
            'site_id' => $this->site->id, 'company_id' => $this->site->company_id,
            'name' => 'Existing Manager', 'phone' => '480-555-0333',
            'position' => 'manager', 'employment_type' => Employee::TYPE_DIRECT, 'employment_status' => 'active',
        ]);
        $account = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $originalPassword = $account->password;

        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Another Name', 'phone' => '(480) 555-0333',
        ])->assertSessionHasErrors('phone');
        $this->post(route('worker-join.store', $this->site), [
            'full_name' => 'Existing Manager', 'phone' => '480-555-0444',
        ])->assertSessionHasErrors('full_name');

        $this->assertGuest();
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('member_registrations', 0);
        $this->assertDatabaseCount('worker_devices', 0);
        $this->assertSame('480-555-0333', $employee->fresh()->phone);
        $this->assertSame('admin', $account->fresh()->access_role);
        $this->assertSame($originalPassword, $account->fresh()->password);
    }
}
