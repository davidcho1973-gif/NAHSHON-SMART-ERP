<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** A phone suffix or employee ID is attendance identification, never an app credential. */
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

    public function test_phone_suffixes_do_not_disclose_accounts_or_open_the_personal_app(): void
    {
        $worker = $this->person('이대웅', '480-555-0142', 'worker');
        $this->postJson(route('worker-app.find'), ['last4' => '0142'])
            ->assertForbidden()->assertJsonPath('code', 'personal_app_sign_in_required')
            ->assertJsonMissingPath('workers')->assertDontSee($worker->name);
        $this->postJson(route('worker-app.enter'), ['employee_id' => $worker->id])
            ->assertForbidden()->assertJsonMissingPath('device_token');
        $this->assertGuest();
        $this->assertDatabaseCount('worker_devices', 0);
    }

    public function test_a_foreman_and_a_manager_enter_the_app_only_with_an_authenticated_account(): void
    {
        foreach ([['반장', '480-555-0111', 'foreman', 'foreman'], ['소장', '480-555-0122', 'site_manager', 'superintendent']] as [$name, $phone, $role, $position]) {
            $person = $this->person($name, $phone, $role, $position);

            $this->postJson(route('worker-app.enter'), ['employee_id' => $person->id])
                ->assertForbidden()->assertJsonPath('code', 'personal_app_sign_in_required');
            $this->assertGuest();
            $this->actingAsPersonalAppUser($person->user);
            $this->assertAuthenticatedAs($person->user);
            $this->get(route('attendance-app.index'))->assertOk();
            $this->post(route('logout'));
        }
    }

    public function test_an_employee_id_cannot_impersonate_a_manager_or_create_a_device(): void
    {
        $manager = $this->person('오아현', '480-555-0133', 'admin', 'office');

        $beforeUsers = User::count();
        $this->postJson(route('worker-app.enter'), ['employee_id' => $manager->id, 'access_role' => 'super_admin'])
            ->assertForbidden()->assertJsonPath('code', 'personal_app_sign_in_required');
        $this->assertGuest();
        $this->assertDatabaseCount('worker_devices', 0);
        $this->assertDatabaseCount('users', $beforeUsers);
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_an_existing_worker_session_cannot_switch_to_a_manager_by_employee_id(): void
    {
        $manager = $this->person('소장', '480-555-0122', 'site_manager', 'superintendent');
        $worker = $this->person('작업자', '480-555-0144', 'worker');
        $this->actingAs($worker->user)->postJson(route('worker-app.enter'), ['employee_id' => $manager->id])
            ->assertForbidden()->assertJsonMissingPath('device_token');
        $this->assertAuthenticatedAs($worker->user);
        $this->assertDatabaseCount('worker_devices', 0);
    }

    public function test_an_old_manager_recaller_cannot_restore_unrestricted_erp_access(): void
    {
        $manager = $this->person('소장', '480-555-0122', 'site_manager', 'superintendent')->user;
        $manager->forceFill(['remember_token' => str_repeat('r', 60)])->save();
        $cookie = $manager->getAuthIdentifier().'|'.$manager->getRememberToken().'|'.$manager->getAuthPassword();

        $this->withCookie(Auth::guard()->getRecallerName(), $cookie)->get('/')
            ->assertRedirect(route('login', ['erp' => 1]))
            ->assertSessionHas(WorkerDeviceSession::FLAG, true)
            ->assertSessionHas(PersonalAppAccessService::LEGACY_SESSION, true);

        $this->assertAuthenticatedAs($manager);
        $this->get(route('attendance-app.index'))->assertOk();
        $this->postJson('/vehicle-api/save', [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
        $this->postJson('/auth/password/setup', [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
    }

    public function test_a_phone_only_manager_session_must_sign_in_before_using_the_business_app(): void
    {
        foreach (['foreman', 'site_manager'] as $i => $role) {
            $manager = $this->person('Manager '.$i, '480555018'.$i, $role)->user;
            $this->actingAs($manager)->withSession([WorkerDeviceSession::FLAG => true])
                ->get(route('attendance-app.index'))->assertRedirect(route('login', ['app' => 1]));
            $this->getJson(route('attendance-app.home'))->assertForbidden()
                ->assertJsonPath('code', 'personal_app_sign_in_required');
            $this->get(route('worker-app.entry'))->assertRedirect(route('login', ['app' => 1]));
            $this->post(route('logout'));
        }
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
            $this->get('/')->assertRedirect($role === 'worker' ? route('worker-app.entry') : route('attendance-app.index'));
            $this->getJson('/')->assertForbidden()->assertJsonPath('reauthenticate_url', null);
            $this->post(route('logout'));
        }
    }

    public function test_four_digits_alone_never_reach_a_terminated_person(): void
    {
        $gone = $this->person('퇴사자', '480-555-0155', 'worker');
        $gone->update(['employment_status' => 'terminated']);

        $this->postJson(route('worker-app.find'), ['last4' => '0155'])->assertForbidden()->assertJsonMissingPath('workers');
        $this->postJson(route('worker-app.enter'), ['employee_id' => $gone->id])->assertForbidden();
        $this->assertGuest();
    }

    public function test_fewer_than_four_digits_never_lists_anybody(): void
    {
        $this->person('이대웅', '480-555-0142', 'worker');

        foreach (['', '4', '42', '142'] as $partial) {
            $this->postJson(route('worker-app.find'), ['last4' => $partial])
                ->assertForbidden()->assertJsonMissingPath('workers');
        }
    }
}
