<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\Site;
use App\Models\UnifiedAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 현장 QR 로 스스로 등록한 사람은 «소속 미확인» 으로 들어온다.
 *
 * 예전에는 회사를 묻지 않으면서도 현장의 자사 회사로 찍었다. 그래서 협력사 인원까지
 * «자사 직영(시급)» 이 되어 우리 급여 대장에 오르고, 임금률이 없으니 «임금률 미설정»
 * 경고가 떴다 — 묻지 않은 것을 아는 척한 값이었고, 하필 돈이 걸린 쪽이 틀렸다.
 *
 * 이제는 모르는 것을 모른다고 적는다: 인원은 세되 급여 시트는 만들지 않고,
 * 인사가 회사를 확인하는 순간 진짜 값이 된다.
 */
class SelfRegisteredSoulIsNotOnOurPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $own = Company::create([
            'code' => 'OWN', 'name' => 'ABC MEP', 'status' => 'active',
            'company_type' => Company::TYPE_OWN,
        ]);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site', 'company_id' => $own->id,
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function register(string $name = '이대웅', string $phone = '480-555-0142'): TestResponse
    {
        return $this->post('/join/w/'.$this->site->id, [
            'full_name' => $name,
            'phone' => $phone,
        ]);
    }

    public function test_a_self_registered_worker_starts_with_no_company_and_no_trade(): void
    {
        $this->register()->assertOk();

        $employee = Employee::query()->sole();

        $this->assertNull($employee->company_id, '묻지 않은 회사를 아는 척하지 않는다');
        $this->assertSame(Employee::TYPE_UNVERIFIED, $employee->employment_type);
        $this->assertNull($employee->role, "'미지정' 은 공정처럼 생긴 글자라 집계에 한 칸을 차지한다");
        $this->assertSame('active', $employee->employment_status, '등록한 사람은 오늘부터 현장에 있다');
    }

    public function test_an_unconfirmed_worker_is_counted_but_never_paid(): void
    {
        $this->register()->assertOk();
        $employee = Employee::query()->sole();

        // 인원은 센다 — 현장에 서 있는 사람이니까.
        $this->assertSame(Employee::POLICY_HEADCOUNT, $employee->attendancePolicy());
        $this->assertFalse($employee->isHourly(), '누가 임금을 주는지 모르는 사람을 시급 정산에 올리지 않는다');

        // 급여 쪽은 조용하다 — 시트도, 임금률 경고도 없다.
        $this->assertSame(0, PayrollTimesheet::query()->where('employee_id', $employee->id)->count());
        $this->assertFalse(
            UnifiedAlert::query()->where('event_type', 'payroll_setup_missing')->exists(),
            '소속을 모르는 사람에게 임금률을 재촉하면, 진짜 자사 직영의 경고가 그 소음에 묻힌다',
        );
    }

    public function test_hr_still_has_to_look_at_the_new_person(): void
    {
        $this->register()->assertOk();

        $this->assertTrue(
            UnifiedAlert::query()->where('event_type', 'worker_self_registration_review')->exists(),
            '급여 경고를 끈 만큼 «확인해 달라» 는 말은 남아 있어야 한다',
        );
        $this->assertTrue((bool) data_get(Employee::query()->sole()->payload, 'self_registered_pending_hr'));
    }

    /** «미확인» 은 시작 상태일 뿐 답이 아니다 — 사람이 고르는 목록에는 없다. */
    public function test_unverified_is_never_offered_as_an_answer(): void
    {
        $this->assertArrayHasKey(Employee::TYPE_UNVERIFIED, Employee::EMPLOYMENT_TYPES);
        $this->assertArrayNotHasKey(Employee::TYPE_UNVERIFIED, Employee::ASSIGNABLE_EMPLOYMENT_TYPES);
    }
}
