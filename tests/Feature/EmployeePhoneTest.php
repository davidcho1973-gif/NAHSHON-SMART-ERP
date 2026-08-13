<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 직원의 전화번호 — 앱 링크를 문자·왓츠앱으로 "바로" 보내기 위한 것.
 *
 * 번호가 없으면 반장이 보내기 화면에서 받는 사람을 매번 손으로 고른다. 등록 폼이
 * 이미 받아 둔 값을 또 묻는 셈이라, 등록 → 직원 정보로 번호가 따라와야 한다.
 *
 * 저장만으로는 부족하다. 미국 번호 `480-555-0100` 을 그대로 `wa.me` 에 넣으면
 * 링크가 열리지 않는다. 국가번호가 붙은 숫자만 남긴 형태여야 한다.
 */
class EmployeePhoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function employee(array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'name' => 'Cristian rosas', 'company_id' => $this->company->id,
            'employment_status' => 'active', 'preferred_language' => 'es',
        ], $extra));
    }

    // ── 번호를 보낼 수 있는 형태로 바꾼다 ────────────────────────────────

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function numbers(): array
    {
        return [
            '하이픈 미국 번호' => ['480-555-0100', '14805550100'],
            '괄호와 공백' => ['(480) 555-0100', '14805550100'],
            '이미 국가번호가 붙음' => ['+1 480 555 0100', '14805550100'],
            '점으로 구분' => ['480.555.0100', '14805550100'],
            '한국 번호' => ['+82 10-1234-5678', '821012345678'],
            '숫자가 모자람' => ['555-0100', null],
            '글자만' => ['모름', null],
            '빈 값' => ['', null],
            '없음' => [null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numbers')]
    public function test_it_turns_a_typed_number_into_a_dialable_one(?string $typed, ?string $expected): void
    {
        $this->assertSame($expected, $this->employee(['phone' => $typed])->dialNumber());
    }

    // ── 두 번 묻지 않는다 ──────────────────────────────────────────────

    public function test_the_number_from_the_registration_form_follows_the_employee(): void
    {
        // 간편등록에서 이미 받은 번호다. 직원 정보에서 또 묻지 않는다.
        MemberRegistration::create([
            'full_name' => 'Dhairo Carmona',
            'phone' => '480-555-0199',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
        ]);

        $employee = Employee::query()->where('name', 'Dhairo Carmona')->firstOrFail();

        $this->assertSame('480-555-0199', $employee->phone);
        $this->assertSame('14805550199', $employee->dialNumber());
    }

    public function test_an_admin_can_save_and_read_back_the_number(): void
    {
        $this->actingAs($this->admin());
        $svc = app(EmployeeAdminService::class);

        $saved = $svc->save([
            'name' => 'Cristian rosas', 'companyId' => (string) $this->company->id,
            'employmentType' => 'direct', 'status' => 'active', 'phone' => ' 480-555-0100 ',
        ]);

        $this->assertTrue($saved['success']);
        $this->assertDatabaseHas('employees', ['id' => $saved['id'], 'phone' => '480-555-0100']);

        $row = collect($svc->list()['rows'])->firstWhere('id', $saved['id']);
        $this->assertSame('480-555-0100', $row['phone']);
    }

    public function test_clearing_the_number_leaves_it_empty_rather_than_blank_text(): void
    {
        // 빈 문자열이 남으면 dialNumber() 는 null 을 주지만 화면은 "번호 있음"으로 읽는다.
        $this->actingAs($this->admin());
        $employee = $this->employee(['phone' => '480-555-0100']);

        app(EmployeeAdminService::class)->save([
            'id' => $employee->id, 'name' => $employee->name,
            'companyId' => (string) $this->company->id,
            'employmentType' => 'direct', 'status' => 'active', 'phone' => '',
        ]);

        $this->assertNull($employee->fresh()->phone);
    }

    // ── 보내기 화면이 그 번호를 실제로 쓴다 ──────────────────────────────

    public function test_the_send_screen_dials_the_saved_number(): void
    {
        $employee = $this->employee(['phone' => '480-555-0100']);

        $res = $this->actingAs($this->admin())
            ->get(route('attendance-app.employee.share', ['employee' => $employee]))
            ->assertOk();

        $res->assertSee('480-555-0100');           // 누구에게 가는지 보인다
        $res->assertSee('"14805550100"', false);   // 문자·왓츠앱이 그 번호로 열린다
    }

    public function test_the_send_screen_says_so_when_there_is_no_number(): void
    {
        // 조용히 빈 링크를 주면 반장은 왜 안 열리는지 모른다.
        $res = $this->actingAs($this->admin())
            ->get(route('attendance-app.employee.share', ['employee' => $this->employee()]))
            ->assertOk();

        $res->assertSee('전화번호가 없습니다');
        $res->assertSee('null', false);
    }

    public function test_the_employee_form_asks_for_the_number(): void
    {
        $js = file_get_contents(public_path('js/admin-employees.js'));

        $this->assertStringContainsString("name: 'phone'", $js);
        $this->assertStringContainsString('전화번호', $js);
    }
}
