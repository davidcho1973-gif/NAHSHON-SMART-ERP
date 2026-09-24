<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 작업자는 이메일·비밀번호를 물어보는 화면을 <b>만나지 않는다.</b>
 *
 * ── 겪은 일 (2026-09-21) ───────────────────────────────────────────────
 * 사장님이 「작업자 앱」 을 눌러 보니 나손 ERP 로그인 화면이 떠서 이메일과 비밀번호를
 * 물었다. 작업자에게는 둘 다 없다 — 거기가 막다른 길이었다.
 *
 * 원인은 «앱이 로그인을 요구한다» 가 아니라 <b>작업자를 알아보는 방법이 이미 있는데
 * 앱이 그걸 안 썼다</b> 는 것이다. 등록할 때 그 휴대폰에 기기 토큰을 발급했고 게이트는
 * 그것만으로 사람을 알아본다. 앱만 사무직과 같은 문을 쓰고 있었다.
 *
 * 그래서 등급을 둔다:
 *   · 출퇴근  — 휴대폰을 기억한 것만으로. 0단계. 안 찍힌 출퇴근은 그 사람 임금이다.
 *   · 메시지·문서 — PIN 네 자리 한 번. 거기 있는 글은 남의 것이다.
 */
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

    public function test_a_remembered_phone_opens_the_app_without_any_password(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken])
            ->assertRedirect(route('attendance-app.index'));

        $this->assertAuthenticatedAs($this->workerUser);
        $this->get(route('attendance-app.index'))->assertOk();
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
    }

    public function test_the_phone_is_remembered_so_the_handoff_happens_only_once(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken])
            ->assertCookie(WorkerDeviceSession::COOKIE);
    }

    public function test_a_phone_carrying_the_cookie_walks_straight_in(): void
    {
        $this->withCookie(WorkerDeviceSession::COOKIE, $this->deviceToken)
            ->get(route('worker-app.entry'))
            ->assertRedirect(route('attendance-app.index'));

        $this->assertAuthenticatedAs($this->workerUser);
    }

    // ── 열쇠가 아닌 것은 열리지 않는다 ──────────────────────────────────

    public function test_a_made_up_token_opens_nothing(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => str_repeat('a', 48)])
            ->assertRedirect(route('worker-app.entry', ['retry' => 1]));

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

    /**
     * 메시지·문서도 같은 문으로 열린다 — 한 겹 더 묻지 않는다.
     *
     * 예전에는 여기서 PIN 네 자리를 한 번 받았다. 사장님 결정(2026-09-23)으로 PIN 이
     * 사라졌으므로 그 한 겹을 요구할 수단도 없다. 대신 이 문으로 들어온 세션은
     * ERP 본화면에 닿지 못한다(FourDigitDoorTest 가 그 쪽을 잠근다).
     */
    public function test_the_same_phone_opens_messages_and_documents_too(): void
    {
        $this->post(route('worker-app.device'), ['device_token' => $this->deviceToken]);

        foreach (['attendance-app.index', 'communication.index', 'attendance-app.docs'] as $name) {
            $this->get(route($name))->assertOk();
        }
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
        $this->actingAs($boss)->get(route('communication.index'))->assertOk();
    }
}
