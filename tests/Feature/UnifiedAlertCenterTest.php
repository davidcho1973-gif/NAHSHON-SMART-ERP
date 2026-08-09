<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 통합 알림 센터 — 읽기와 쓰기가 같은 저장소를 봐야 한다.
 *
 * 오랫동안 목록(api_getAlerts)은 하드코딩 데모 3건을 돌려주고, 상태 변경
 * (api_updateAlertStatus)은 unified_alerts 를 갱신했다. 화면에 뜬 알림을
 * 완료 처리하면 "찾을 수 없음"이 나는 구조였고, 계약·비자·장비 만료를 모으는
 * 수집기(refreshKnownModules)는 부르는 곳이 없어 한 번도 돌지 않았다.
 */
class UnifiedAlertCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_비자_만료_임박이_알림센터_목록에_뜬다(): void
    {
        Employee::create([
            'name' => '김철수', 'employee_number' => 'E-001', 'employment_status' => 'active',
            'visa_expires_on' => today()->addDays(5),
        ]);
        $this->actingAs($this->admin());

        $alerts = SmartCompanyData::alerts('all');

        $visa = collect($alerts)->firstWhere('type', 'visa_expiry');
        $this->assertNotNull($visa, '수집기가 실행되지 않으면 비자 만료가 영영 안 보인다');
        $this->assertSame('HR', $visa['module']);
        $this->assertSame('긴급', $visa['severity'], '7일 이내 만료는 긴급이다');
        $this->assertStringContainsString('김철수', $visa['title']);
    }

    public function test_목록에_뜬_알림을_그_i_d_로_완료_처리할_수_있다(): void
    {
        // 읽기/쓰기가 다른 저장소를 보던 시절엔 이게 "찾을 수 없음"으로 실패했다.
        Employee::create([
            'name' => '김철수', 'employee_number' => 'E-001', 'employment_status' => 'active',
            'visa_expires_on' => today()->addDays(5),
        ]);
        $this->actingAs($this->admin());

        $alerts = SmartCompanyData::alerts('all');
        $id = collect($alerts)->firstWhere('type', 'visa_expiry')['id'];

        $res = SmartCompanyData::updateAlertStatus($id, '완료');

        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame('completed', UnifiedAlert::where('alert_code', $id)->value('status'));
    }

    public function test_로그인하지_않으면_빈_목록이다(): void
    {
        $this->assertSame([], SmartCompanyData::alerts('all'));
    }

    public function test_모듈_필터가_동작한다(): void
    {
        Employee::create([
            'name' => '김철수', 'employee_number' => 'E-001', 'employment_status' => 'active',
            'visa_expires_on' => today()->addDays(5),
        ]);
        $this->actingAs($this->admin());

        $hr = SmartCompanyData::alerts('HR');
        $doc = SmartCompanyData::alerts('DOC');

        $this->assertNotEmpty($hr);
        $this->assertSame([], array_filter($doc, fn (array $a) => $a['module'] !== 'DOC'));
    }
}
