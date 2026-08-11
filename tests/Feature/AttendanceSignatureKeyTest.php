<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 오프라인 출퇴근 서명 키가 화면으로 새지 않는가.
 *
 * 현장에 신호가 없을 때 휴대폰이 기록을 안고 있다가 나중에 올린다. 그 사이 시각과
 * 위치를 고칠 수 있어 브라우저에서 서명을 붙이는데, 그러려면 키가 브라우저에도
 * 있어야 한다. 피할 수 없는 구조다.
 *
 * 피할 수 있는 것은 <b>어떤 키를 주느냐</b>다. APP_KEY 를 그대로 보내면 화면 소스를
 * 볼 수 있는 사람이 세션·쿠키·암호화를 전부 여는 열쇠를 가져간다. 출퇴근 하나
 * 지키려고 집 열쇠를 통째로 주는 셈이다.
 */
class AttendanceSignatureKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_derived_key_is_not_the_application_key(): void
    {
        $this->assertNotSame((string) config('app.key'), AttendanceSignature::key());
    }

    public function test_the_derived_key_does_not_contain_the_application_key(): void
    {
        // 이어 붙이기만 해서는 안 된다 — 잘라내면 원래 열쇠가 나온다.
        $app = (string) config('app.key');

        $this->assertStringNotContainsString($app, AttendanceSignature::key());
        $this->assertStringNotContainsString(
            str_replace('base64:', '', $app),
            AttendanceSignature::key()
        );
    }

    public function test_it_refuses_to_sign_without_an_application_key(): void
    {
        // 예비 키를 소스에 두면 그 값이 곧 진짜 키가 된다. 차라리 멈춘다.
        config(['app.key' => '']);

        $this->expectException(\RuntimeException::class);
        AttendanceSignature::key();
    }

    public function test_the_same_message_always_signs_the_same_way(): void
    {
        // 서버와 브라우저가 같은 값을 내야 검증이 성립한다.
        $this->assertSame(AttendanceSignature::sign('a_b_c'), AttendanceSignature::sign('a_b_c'));
        $this->assertNotSame(AttendanceSignature::sign('a_b_c'), AttendanceSignature::sign('a_b_d'));
    }

    public function test_the_erp_page_never_prints_the_application_key(): void
    {
        // 이 테스트가 목적이다. 화면 소스에 APP_KEY 가 한 번이라도 찍히면
        // 그 배포의 모든 세션이 위조 가능해진다.
        $company = Company::create(['code' => 'DP', 'name' => 'TEST CO', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $company->id, 'name' => '테스터', 'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'super_admin',
            'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $key = (string) config('app.key');
        $this->assertStringNotContainsString($key, $html);
        $this->assertStringNotContainsString(str_replace('base64:', '', $key), $html);
    }
}
