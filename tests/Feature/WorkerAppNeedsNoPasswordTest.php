<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Ordinary workers use the site QR; old app icons recover without granting business access. */
class WorkerAppNeedsNoPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $workerUser;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => '1 현장',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        $this->employee = Employee::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'name' => 'John Smith', 'first_name' => 'John', 'last_name' => 'Smith',
            'phone' => '4805550100', 'employment_status' => 'active',
        ]);

        $this->workerUser = User::query()->create([
            'name' => 'John Smith', 'email' => null, 'password' => Hash::make('x'),
            'employee_id' => $this->employee->id,
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->deviceToken = WorkerDevice::issueFor($this->employee, 'phone', verified: true);
    }

    // ── 출퇴근: 0단계 ───────────────────────────────────────────────────

    public function test_a_remembered_phone_returns_to_site_attendance_without_signing_into_the_app(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken])
            ->assertRedirect(route('gate.show', $this->employee->site_id));

        $this->assertGuest();
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_a_worker_who_is_not_signed_in_never_lands_on_the_erp_login(): void
    {
        // 홈 화면 아이콘·북마크로 앱 주소를 바로 여는 경우 — 여기서 /login 으로 보내면
        // 이메일과 비밀번호를 묻는 화면이 뜨고 작업자는 거기서 끝난다.
        $this->get(route('attendance-app.index'))
            ->assertRedirect(route('worker-app.entry'));
    }

    public function test_the_worker_door_never_asks_for_an_email_or_a_password(): void
    {
        $html = (string) $this->get(route('worker-app.entry'))->assertOk()->getContent();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString('name="email"', $html);
        $this->assertStringContainsString(route('login', ['app' => 1]), $html);
        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertStringNotContainsString('name="last4"', $html);
    }

    public function test_the_recovery_handoff_does_not_mint_a_new_device_or_an_app_session(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken])
            ->assertRedirect(route('gate.show', $this->employee->site_id));
        $this->assertDatabaseCount('worker_devices', 1);
        $this->assertGuest();
    }

    public function test_an_old_home_screen_icon_with_a_cookie_shows_a_safe_site_link(): void
    {
        $this->withCookie(WorkerDeviceSession::COOKIE, $this->deviceToken)
            ->get(route('worker-app.entry'))
            ->assertOk()->assertViewIs('worker-app.access')
            ->assertViewHas('gateUrl', route('gate.show', $this->employee->site_id))
            ->assertSee('id="attendance-link"', false)
            ->assertDontSee('rel="manifest"', false);

        $this->assertGuest();
    }

    // ── 열쇠가 아닌 것은 열리지 않는다 ──────────────────────────────────

    public function test_a_made_up_token_opens_nothing(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => str_repeat('a', 48)])
            ->assertRedirect(route('worker-app.entry'));

        $this->assertGuest();
    }

    public function test_a_phone_belonging_to_someone_who_left_opens_nothing(): void
    {
        $this->employee->forceFill(['employment_status' => 'terminated'])->save();

        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken]);

        $this->assertGuest();
    }

    public function test_a_phone_cannot_walk_into_a_manager_account(): void
    {
        // 벽에 붙은 QR 로 등록한 휴대폰 한 대가 관리자 열쇠가 되면 안 된다.
        // 관리자 계정이 보는 것은 자기 기록만이 아니다.
        $this->workerUser->forceFill(['access_role' => 'site_manager', 'access_scope' => 'site'])->save();

        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken]);

        $this->assertGuest();
    }

    public function test_both_weak_worker_entries_discard_earlier_strong_and_personal_app_proof(): void
    {
        $request = request();
        $request->setLaravelSession(app('session.store'));

        foreach (['phone', 'remembered-device'] as $entry) {
            $request->session()->put([
                EmailPasswordAuthService::STRONG_AUTH_SESSION => $this->workerUser->id,
                EmailPasswordAuthService::SETUP_SESSION => ['user_id' => $this->workerUser->id],
                PersonalAppAccessService::SESSION => 123,
                PersonalAppAccessService::LEGACY_SESSION => true,
            ]);

            $opened = $entry === 'phone'
                ? WorkerDeviceSession::openForApp($request, $this->employee->fresh())
                : WorkerDeviceSession::openFor($request, $this->deviceToken);

            $this->assertTrue($opened);
            $this->assertAuthenticatedAs($this->workerUser);
            $this->assertTrue($request->session()->get(WorkerDeviceSession::FLAG));
            $this->assertFalse($request->session()->has(EmailPasswordAuthService::STRONG_AUTH_SESSION));
            $this->assertFalse($request->session()->has(EmailPasswordAuthService::SETUP_SESSION));
            $this->assertFalse($request->session()->has(PersonalAppAccessService::SESSION));
            $this->assertFalse($request->session()->has(PersonalAppAccessService::LEGACY_SESSION));
        }
    }

    public function test_a_worker_session_cannot_open_business_pages_even_with_install_or_role_parameters(): void
    {
        $this->actingAs($this->workerUser);

        foreach (['attendance-app.index', 'communication.index', 'attendance-app.docs'] as $name) {
            $this->get(route($name, ['install' => 1, 'access_role' => 'foreman']))
                ->assertRedirect(route('worker-app.entry'));
        }
        $this->get(route('worker-app.entry'))->assertOk()->assertViewIs('worker-app.access');
        $this->assertSame('worker', $this->workerUser->fresh()->access_role);
    }

    public function test_a_worker_cannot_bypass_the_business_app_through_its_json_endpoints(): void
    {
        $this->actingAsPersonalAppUser($this->workerUser);
        foreach (['/attendance-app/home', '/expense-app/list', '/mobile-expense/index', '/attendance-app/docs', '/attendance-app/ask', '/attendance-app/messages'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'personal_app_role_required');
        }
        foreach (['/attendance-app/punch', '/expense-app/submit', '/mobile-expense/store', '/attendance-app/messages/direct', route('mobile-expense.upload-receipt'), route('docs.upload'), route('ops.voice'), route('push.subscribe')] as $url) {
            $this->postJson($url, ['employee_id' => $this->employee->id, 'access_role' => 'super_admin'])
                ->assertForbidden()->assertJsonPath('code', 'personal_app_role_required');
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    // ── 사무직은 지금까지 쓰던 문을 그대로 쓴다 ─────────────────────────

    public function test_office_staff_are_still_sent_to_the_normal_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_signed_in_office_user_is_not_treated_as_device_only(): void
    {
        $boss = User::query()->create([
            'name' => 'Boss', 'email' => 'boss@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        // 정식 로그인 세션에는 「휴대폰만으로」 표시가 없으니 PIN 을 묻지 않는다.
        $this->actingAsPersonalAppUser($boss)->get(route('communication.index'))->assertOk();
    }
}
