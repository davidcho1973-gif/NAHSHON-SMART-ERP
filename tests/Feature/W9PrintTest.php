<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\W9Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 직원 관리에서 바로 뽑는 W-9.
 *
 * 자동으로 채울 수 있는 것과 없는 것이 갈린다. 이름·주소·세무분류는 우리가 안다.
 * TIN 과 서명은 못 채운다 — TIN 은 가진 적이 없고, 서명은 "위증 시 처벌을 감수한다"는
 * 본인 진술이라 대신 쓰면 서류 위조다. 그래서 종이는 그 두 칸만 비워서 낸다.
 */
class W9PrintTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'name' => 'Cristian rosas', 'employment_status' => 'active',
            'payload' => ['address' => '1234 W Main St', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85001'],
        ]);
    }

    private function payrollUser(): User
    {
        return User::factory()->create([
            'access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_the_form_is_prefilled_from_what_we_already_know(): void
    {
        $prefill = W9Form::prefillFor($this->employee);

        $this->assertSame('Cristian rosas', $prefill['legal_name']);
        $this->assertSame('1234 W Main St', $prefill['address']);
        $this->assertSame('Phoenix AZ 85001', $prefill['city_state_zip']);
        $this->assertSame('individual', $prefill['tax_classification']);
    }

    public function test_the_prefill_never_invents_a_tin_or_a_signature(): void
    {
        // 이게 이 기능의 경계선이다. 서명을 대신 쓰면 서류 위조가 된다.
        $prefill = W9Form::prefillFor($this->employee);

        $this->assertArrayNotHasKey('tin', $prefill);
        $this->assertArrayNotHasKey('signature_name', $prefill);
    }

    public function test_an_unsubmitted_w9_still_prints_with_the_known_fields(): void
    {
        // "제출된 W-9 이 없습니다" 만 띄우면 정작 현장에서 손으로 받을 때 못 쓴다.
        $res = $this->actingAs($this->payrollUser())
            ->get(route('w9.print', ['employee' => $this->employee]))
            ->assertOk();

        $res->assertSee('Cristian rosas');
        $res->assertSee('1234 W Main St');
        $res->assertSee('아직 제출되지 않았습니다');
        $res->assertSee('작성 링크 열기');
    }

    public function test_a_submitted_w9_prints_as_a_record_copy(): void
    {
        W9Form::create([
            'employee_id' => $this->employee->id, 'legal_name' => 'Cristian Rosas',
            'tax_classification' => 'individual', 'address' => '1234 W Main St',
            'city_state_zip' => 'Phoenix, AZ 85001', 'tin_type' => 'ssn',
            'tin' => '123456789', 'tin_last4' => '6789',
            'signature_name' => 'Cristian Rosas', 'certified_at' => now(), 'status' => 'submitted',
        ]);

        $res = $this->actingAs($this->payrollUser())
            ->get(route('w9.print', ['employee' => $this->employee]))
            ->assertOk();

        $res->assertSee('제출 완료');
        $res->assertSee('Cristian Rosas');
    }

    public function test_the_full_tin_is_not_printed_unless_asked_for(): void
    {
        // 사회보장번호가 인쇄물로 돌아다니는 것은 되돌릴 수 없는 사고다.
        W9Form::create([
            'employee_id' => $this->employee->id, 'legal_name' => 'Cristian Rosas',
            'tax_classification' => 'individual', 'address' => 'x', 'city_state_zip' => 'y',
            'tin_type' => 'ssn', 'tin' => '123456789', 'tin_last4' => '6789',
            'signature_name' => 'Cristian Rosas', 'certified_at' => now(), 'status' => 'submitted',
        ]);

        // 원본 양식처럼 한 칸에 한 자리씩 들어가므로, 칸에 찍힌 숫자를 모아서 본다.
        $masked = $this->ssnDigits($this->actingAs($this->payrollUser())
            ->get(route('w9.print', ['employee' => $this->employee]))->assertOk()->getContent());

        $this->assertSame('6789', $masked, '뒤 4자리 말고 다른 숫자가 인쇄되었습니다.');

        // 1099 신고에는 전체가 필요하다 — 요청하면 나온다.
        $full = $this->ssnDigits($this->actingAs($this->payrollUser())
            ->get(route('w9.print', ['employee' => $this->employee, 'full' => 1]))->assertOk()->getContent());

        $this->assertSame('123456789', $full);
    }

    /** 인쇄된 SSN 격자에 실제로 찍힌 숫자만 뽑아낸다. */
    private function ssnDigits(string $html): string
    {
        preg_match('/data-tin="[a-z0-9]+"(.*?)<\/div>/s', $html, $m);

        return preg_replace('/\D/', '', $m[1] ?? '') ?? '';
    }

    public function test_a_role_that_cannot_see_pay_cannot_print_a_w9(): void
    {
        // W-9 에는 납세자 번호가 들어 있다.
        $siteManager = User::factory()->create([
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $this->actingAs($siteManager)
            ->get(route('w9.print', ['employee' => $this->employee]))
            ->assertForbidden();
    }

    public function test_the_employee_screen_offers_the_print_button(): void
    {
        $js = file_get_contents(public_path('js/admin-employees.js'));

        $this->assertStringContainsString('W-9 출력', $js);
        $this->assertStringContainsString("/print'", $js);
    }

    public function test_the_printout_follows_the_irs_layout(): void
    {
        // 이건 우리 서식이 아니라 국세청 서식이다. 감사에서 읽는 사람은 이 배치를 외우고
        // 있고, 칸이 옮겨져 있으면 "다른 서류" 로 본다.
        $html = $this->actingAs($this->payrollUser())
            ->get(route('w9.print', ['employee' => $this->employee]))->assertOk()->getContent();

        foreach ([
            'Request for Taxpayer',
            'Give form to the',
            'Before you begin.',
            'Part I',
            'Taxpayer Identification Number (TIN)',
            'Part II',
            'Under penalties of perjury',
            'Cat. No. 10231X',
            '(Rev. 3-2024)',
        ] as $mark) {
            $this->assertStringContainsString($mark, $html, "원본에 있는 '{$mark}' 가 빠졌습니다.");
        }

        // Times 로 두면 한눈에 다른 서류로 보인다.
        $this->assertStringContainsString('font-family: Helvetica', $html);
        $this->assertStringNotContainsString('Times New Roman', $html);
    }
}
