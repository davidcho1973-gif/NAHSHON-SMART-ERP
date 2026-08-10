<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\WorkerLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 직영 작업자에게 앱 링크를 보내는 화면.
 *
 * 인쇄 카드와 목적이 다르다. 카드는 손에 쥐여 주는 종이고, 이건 문자·왓츠앱으로 보낸다.
 * 여기서 빠지면 곤란한 것 두 가지 — <b>어느 구글 계정으로 로그인하는지</b>(가장 흔한
 * 실패), 그리고 <b>보낼 문구</b>(링크만 덜렁 보내면 모르는 주소라 안 누른다).
 */
class WorkerAppShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'name' => 'Cristian rosas',
            'employment_status' => 'active', 'preferred_language' => 'es',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function open(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())
            ->get(route('attendance-app.employee.share', ['employee' => $this->employee]));
    }

    public function test_it_gives_the_link_and_a_qr_for_the_worker_app(): void
    {
        $res = $this->open()->assertOk();

        $res->assertSee(route('attendance-app.index'), false);
        $res->assertSee('data:image/svg+xml;base64,', false);   // QR 이 그림으로 박혀 있다
        $res->assertSee('Cristian rosas');
    }

    public function test_it_shows_which_google_account_to_sign_in_with(): void
    {
        User::factory()->create([
            'employee_id' => $this->employee->id, 'email' => 'cristian@gmail.com',
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->open()->assertOk()->assertSee('cristian@gmail.com');
    }

    public function test_it_warns_when_there_is_no_account_to_sign_in_with(): void
    {
        // 계정 없이 링크를 보내면 작업자가 로그인에서 막힌다. 보내기 전에 알아야 한다.
        $this->open()->assertOk()->assertSee('계정 없음')->assertSee('계정 만들기');
    }

    public function test_it_carries_a_ready_made_message_in_three_languages(): void
    {
        $res = $this->open()->assertOk();

        $res->assertSee('내 출퇴근 앱입니다', false);
        $res->assertSee('This is your attendance app', false);
        $res->assertSee('Esta es su aplicación de asistencia', false);
    }

    public function test_every_message_carries_the_link_and_the_two_steps(): void
    {
        // 문구에 주소가 빠지면 보낼 것이 없고, 로그인·홈 화면 안내가 빠지면 열어 놓고 멈춘다.
        $url = route('attendance-app.index');

        foreach (WorkerLang::shareMessage($url) as $code => $text) {
            $this->assertStringContainsString($url, $text, "[{$code}] 문구에 주소가 없습니다.");
            $this->assertMatchesRegularExpression('/구글|Google/u', $text, "[{$code}] 로그인 안내가 없습니다.");
            $this->assertMatchesRegularExpression('/홈 화면|Home Screen|pantalla de inicio/u', $text);
        }
    }

    public function test_it_defaults_to_the_workers_own_language(): void
    {
        // 스페인어 작업자에게 한국어 문구를 보내면 안 읽는다.
        $this->open()->assertOk()->assertSee('"es"', false);
    }

    public function test_a_stranger_cannot_open_someone_elses_share_page(): void
    {
        $other = Employee::create([
            'company_id' => $this->employee->company_id, 'name' => 'Someone Else',
            'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $other->id, 'access_role' => 'worker',
            'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('attendance-app.employee.share', ['employee' => $this->employee]))
            ->assertForbidden();
    }

    public function test_the_employee_screen_offers_the_send_button(): void
    {
        $js = file_get_contents(public_path('js/admin-employees.js'));

        $this->assertStringContainsString('링크 보내기', $js);
        $this->assertStringContainsString("/share'", $js);
    }
}
