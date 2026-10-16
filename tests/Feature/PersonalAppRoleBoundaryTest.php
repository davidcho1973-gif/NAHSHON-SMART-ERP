<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Admin\JobAccessService;
use App\Services\Admin\UserAccessService;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Services\Equipment\EquipmentChecklistService;
use App\Services\Hr\AccessAccountProvisioner;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalAppRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create([
            'code' => 'BOUNDARY', 'name' => 'Boundary Company', 'status' => 'active', 'company_type' => Company::TYPE_OWN,
        ]);
        $this->site = Site::create([
            'company_id' => $company->id, 'code' => 'BOUNDARY', 'name' => 'Boundary Site',
            'status' => 'active', 'timezone' => 'America/New_York',
        ]);
    }

    private function person(string $role): User
    {
        $employee = Employee::create([
            'company_id' => $this->site->company_id, 'site_id' => $this->site->id,
            'name' => 'Returning Person', 'email' => 'returning@example.com', 'phone' => '4805550142',
            'position' => $role === 'worker' ? 'worker' : 'foreman', 'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'email' => $employee->email,
            'access_role' => $role, 'access_scope' => 'self', 'account_status' => 'active',
            'password' => Hash::make('Returning2026'), 'password_set_at' => now(),
            'allowed_company_id' => $employee->company_id, 'allowed_site_id' => $employee->site_id,
        ]);
    }

    private function recaller(User $user): string
    {
        return $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword();
    }

    public static function promotionPaths(): array
    {
        return [['account-management'], ['job-management'], ['hr-provisioning']];
    }

    #[DataProvider('promotionPaths')]
    public function test_a_worker_gate_recaller_cannot_authenticate_after_staff_promotion(string $path): void
    {
        $worker = $this->person('worker');
        $cookieName = Auth::guard()->getRecallerName();
        $this->postJson(route('gate.claim', $this->site), ['employee_id' => $worker->employee_id])
            ->assertOk()->assertJsonPath('success', true)->assertCookie($cookieName);
        $worker->refresh();
        $this->assertNotEmpty($worker->getRememberToken());
        $oldCookie = $this->recaller($worker);

        $issuer = User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAsPersonalAppUser($issuer);
        $this->withSession([WorkerDeviceSession::FLAG => false]);
        if ($path === 'account-management') {
            $result = app(UserAccessService::class)->save([
                'id' => $worker->id, 'name' => $worker->name, 'email' => $worker->email,
                'employeeId' => $worker->employee_id, 'role' => 'foreman', 'scope' => 'self', 'status' => 'active',
                'companyId' => $this->site->company_id, 'siteId' => $this->site->id,
            ]);
            $this->assertTrue($result['success'], json_encode($result));
        } elseif ($path === 'job-management') {
            $result = app(JobAccessService::class)->save($worker->id, [
                'jobRole' => 'site_manager', 'companyId' => $this->site->company_id,
                'siteIds' => [$this->site->id], 'scope' => 'site',
            ]);
            $this->assertTrue($result['success'], json_encode($result));
        } else {
            app(AccessAccountProvisioner::class)->grant($worker->employee, 'foreman');
        }
        $this->assertNull($worker->fresh()->getRawOriginal('remember_token'));

        // Expire only the session: logging out would revoke the cookie independently
        // and hide the elevation bug this test must exercise.
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCookie($cookieName, $oldCookie)->get('/attendance-app')
            ->assertRedirect(route('worker-app.entry'))
            ->assertSessionMissing(PersonalAppAccessService::LEGACY_SESSION);
        $this->assertGuest();

        $this->post('/auth/password/login', [
            'app' => 1, 'email' => $worker->email, 'password' => 'Returning2026',
        ])->assertRedirect('/attendance-app')
            ->assertSessionHas(EmailPasswordAuthService::STRONG_AUTH_SESSION, $worker->id);
        $this->get('/attendance-app')->assertOk();
        $this->assertAuthenticatedAs($worker->fresh());
    }

    public static function staffRoles(): array
    {
        return [['foreman'], ['site_manager']];
    }

    #[DataProvider('staffRoles')]
    public function test_remembered_staff_keep_app_return_and_public_attendance_and_signed_hr_access(string $role): void
    {
        $user = $this->person($role);
        $user->forceFill(['remember_token' => str_repeat('r', 60)])->save();
        $cookie = $this->recaller($user);
        $user->update(['name' => 'Updated Name']);
        $this->assertSame($cookie, $this->recaller($user->fresh()));

        $this->withCookie(Auth::guard()->getRecallerName(), $cookie)->get(route('gate.show', $this->site))
            ->assertOk()->assertSessionHas(PersonalAppAccessService::LEGACY_SESSION, true);
        $this->get('/attendance-app')->assertOk();
        $this->get(route('worker-join.form', $this->site))
            ->assertRedirect(route('gate.show', ['site' => $this->site, 'lang' => 'ko']));
        $this->get(route('gate.show', ['site' => $this->site, 'lang' => 'ko']))->assertOk();

        $device = WorkerDevice::issueFor($user->employee, 'Returning staff phone', verified: true);
        AttendanceLog::create([
            'employee_id' => $user->employee_id, 'site_id' => $this->site->id,
            'event_type' => 'clock_in', 'event_at' => now(), 'source' => 'gate_qr',
        ]);
        $this->postJson(route('gate.notices', $this->site), ['device_token' => $device])
            ->assertOk()->assertJsonStructure(['notices']);
        $this->postJson(route('gate.notices', $this->site), ['device_token' => 'invalid'])->assertForbidden();

        $this->get(URL::signedRoute('w9.show', ['employee' => $user->employee_id]))->assertOk();
        $this->get(route('w9.show', ['employee' => $user->employee_id]))->assertForbidden();
        $registration = MemberRegistration::create([
            'employee_id' => $user->employee_id, 'site_id' => $this->site->id,
            'full_name' => $user->employee->name, 'onboarding_status' => 'pending',
        ]);
        $profileUrl = URL::signedRoute('worker-profile.show', [
            'employee' => $user->employee_id, 'registration' => $registration->id,
        ]);
        $this->get($profileUrl)->assertOk();
        $this->post($profileUrl, [
            'email' => $user->email, 'address' => '100 Main St', 'emergency_contact_name' => 'Family',
            'emergency_contact_phone' => '4805550199',
        ])->assertOk();
        $this->assertSame('100 Main St', $registration->fresh()->address);
        $this->get(route('worker-profile.show', [
            'employee' => $user->employee_id, 'registration' => $registration->id,
        ]))->assertForbidden();
        $this->postJson('/vehicle-api/save', [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
    }

    public function test_demoted_legacy_staff_keep_gate_access_but_lose_the_business_app(): void
    {
        $user = $this->person('foreman');
        $this->actingAs($user)->withSession([
            WorkerDeviceSession::FLAG => true, PersonalAppAccessService::LEGACY_SESSION => true,
        ]);
        $user->update(['access_role' => 'worker']);

        $this->get('/attendance-app')->assertRedirect(route('worker-app.entry'))
            ->assertSessionMissing(PersonalAppAccessService::LEGACY_SESSION)
            ->assertSessionHas(WorkerDeviceSession::FLAG, true);
        $this->get('/app')->assertOk()->assertSee(route('gate.show', $this->site), false);
        $this->get(route('gate.show', $this->site))->assertOk();
        $this->getJson('/attendance-app/home')->assertForbidden()->assertJsonPath('code', 'personal_app_role_required');
        $this->postJson('/vehicle-api/save', [])->assertForbidden();
    }

    public function test_remembered_foreman_can_follow_home_work_and_equipment_links_without_opening_admin_routes(): void
    {
        $user = $this->person('foreman');
        $user->forceFill(['remember_token' => str_repeat('f', 60)])->save();
        $this->withCookie(Auth::guard()->getRecallerName(), $this->recaller($user))
            ->get('/attendance-app')->assertOk();

        foreach (['attendance-app.ops-room', 'expense-app.index', 'attendance-app.docs', 'communication.index',
            'attendance-app.material-receipts', 'worker-enrollment.index', 'user-manual'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['attendance-app.home', 'expense-app.list', 'attendance-app.material-receipts.items',
            'ops.trade-report.status', 'push.key'] as $route) {
            $this->getJson(route($route))->assertOk();
        }

        $equipment = Equipment::create([
            'company_id' => $this->site->company_id, 'site_id' => $this->site->id,
            'equipment_type' => 'Excavator 320', 'model' => 'CAT 320', 'category_group' => 'equipment',
            'trade' => 'heavy', 'status' => Equipment::STATUS_AVAILABLE,
        ]);
        $token = $equipment->ensureQrToken();
        $this->get(route('equipment-checklist.show', $token))->assertOk();
        $template = app(EquipmentChecklistService::class)->templateFor($equipment, 'pre_use');
        $this->assertNotNull($template);
        $this->postJson(route('equipment-checklist.submit', $token), [
            'stage' => 'pre_use', 'answers' => $template->items->mapWithKeys(fn ($item) => [$item->id => ['ok' => true]])->all(),
        ])->assertOk()->assertJsonPath('success', true);

        $this->getJson(route('equipment-checklist.sheet'))->assertForbidden()->assertJsonPath('code', 'personal_app_only');
        $this->postJson(route('worker-enrollment.store'), [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
        $this->postJson('/vehicle-api/save', [])->assertForbidden()->assertJsonPath('code', 'personal_app_only');
    }

    public function test_retired_app_cookie_cleanup_does_not_interrupt_a_public_signed_hr_link(): void
    {
        $user = $this->person('foreman');
        $this->withCookie(PersonalAppAccessService::COOKIE, 'retired-cookie')
            ->get(URL::signedRoute('w9.show', ['employee' => $user->employee_id]))
            ->assertOk()->assertCookieExpired(PersonalAppAccessService::COOKIE);
        $this->assertGuest();
    }
}
