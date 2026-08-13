<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Services\Payroll\CertifiedPayrollRequirement;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 계약이 요구하는 인증임금(WH-347)을 급여가 읽는다.
 *
 * project_contracts 와 projects 에 스위치가 있는데 급여 엔진이 이 값을 읽지 않아,
 * 인증 임금대장을 내야 하는 계약인데도 일반 급여로 계산되고 담당자가 기억해서
 * 따로 챙겨야 했다. 미국 공공·원청 현장에서 이건 컴플라이언스 문제다.
 *
 * 판단은 보수적이어야 한다 — 대상인데 놓치면 법적 문제이고,
 * 대상이 아닌데 표시되면 확인 한 번으로 끝난다.
 */
class CertifiedPayrollRequirementTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private Carbon $start;

    private Carbon $end;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->start = Carbon::parse('2026-08-03');
        $this->end = Carbon::parse('2026-08-16');
    }

    private function contract(array $attrs = []): ProjectContract
    {
        static $n = 0;
        $n++;

        return ProjectContract::create(array_merge([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'internal_reference' => 'PC-'.$n,
            'title' => 'LG ESS 전기공사',
            'status' => 'active',
            'certified_payroll_required' => true,
        ], $attrs));
    }

    private function worker(string $name, ?int $siteId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $siteId ?: $this->site->id,
            'name' => $name,
            'role' => 'Electrician',
            'employment_status' => 'active',
        ]);
    }

    private function check(string $scope = 'ALL'): array
    {
        return app(CertifiedPayrollRequirement::class)->forPeriod($scope, $this->start, $this->end);
    }

    // ── 대상 판정 ────────────────────────────────────────────────────────

    public function test_a_contract_requiring_certified_payroll_puts_the_site_in_scope(): void
    {
        $this->contract();
        $this->worker('강민철');
        $this->worker('이수현');

        $res = $this->check();

        $this->assertTrue($res['required']);
        $this->assertSame(2, $res['headcount'], '이번 주 인증임금 대상 N명 이 대시보드에 떠야 한다');
        $this->assertSame('contract', $res['sources'][0]['type']);
        $this->assertSame('LG ESS 전기공사', $res['sources'][0]['label'], '어느 계약 때문인지 이름이 나와야 한다');
    }

    public function test_prevailing_wage_alone_is_enough(): void
    {
        // 둘 중 하나만 켜져 있어도 대상이다.
        $this->contract(['certified_payroll_required' => false, 'prevailing_wage_required' => true]);
        $this->worker('강민철');

        $this->assertTrue($this->check()['required']);
    }

    public function test_a_project_flag_also_puts_the_site_in_scope(): void
    {
        Project::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'project_code' => 'PRJ-1',
            'name' => 'PHOENIX 2공장',
            'construction_type' => 'electrical',
            'certified_payroll_required' => true,
        ]);
        $this->worker('강민철');

        $res = $this->check();

        $this->assertTrue($res['required']);
        $this->assertSame('project', $res['sources'][0]['type']);
    }

    public function test_no_flag_anywhere_means_not_required(): void
    {
        $this->contract(['certified_payroll_required' => false, 'prevailing_wage_required' => false]);
        $this->worker('강민철');

        $res = $this->check();

        $this->assertFalse($res['required']);
        $this->assertSame(0, $res['headcount']);
    }

    // ── 계약 상태와 기간 ──────────────────────────────────────────────────

    public function test_a_draft_contract_does_not_trigger_the_requirement(): void
    {
        $this->contract(['status' => 'draft']);
        $this->worker('강민철');

        $this->assertFalse($this->check()['required'], '아직 체결 전인 계약이 급여를 바꾸면 안 된다');
    }

    public function test_a_contract_that_ended_before_the_period_does_not_trigger(): void
    {
        $this->contract(['starts_on' => '2026-01-01', 'ends_on' => '2026-07-31']);
        $this->worker('강민철');

        $this->assertFalse($this->check()['required']);
    }

    public function test_a_contract_that_starts_after_the_period_does_not_trigger(): void
    {
        $this->contract(['starts_on' => '2026-09-01']);
        $this->worker('강민철');

        $this->assertFalse($this->check()['required']);
    }

    public function test_a_contract_without_dates_still_triggers(): void
    {
        // 날짜를 안 넣었다고 해서 인증임금 요건이 사라지지는 않는다.
        // 여기서 빠뜨리면 대장을 못 내고, 그건 되돌릴 수 없다.
        $this->contract(['starts_on' => null, 'ends_on' => null]);
        $this->worker('강민철');

        $this->assertTrue($this->check()['required']);
    }

    public function test_a_contract_overlapping_only_partly_still_triggers(): void
    {
        $this->contract(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-31']);
        $this->worker('강민철');

        $this->assertTrue($this->check()['required'], '기간에 하루라도 걸치면 대상이다');
    }

    // ── 범위 ────────────────────────────────────────────────────────────

    public function test_only_the_affected_sites_workers_are_counted(): void
    {
        $clean = Site::create(['code' => 'TX-02', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $this->contract();
        $this->worker('대상자');
        $this->worker('무관한 현장', $clean->id);

        $res = $this->check();

        $this->assertSame(1, $res['headcount'], '요건이 없는 현장 인원까지 세면 대상자 수가 부풀려진다');
        $this->assertSame('LG PHOENIX', $res['sites'][0]['name']);
    }

    public function test_a_site_scoped_run_ignores_other_sites_contracts(): void
    {
        $other = Site::create(['code' => 'TX-02', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);
        ProjectContract::create([
            'company_id' => $this->company->id, 'site_id' => $other->id,
            'internal_reference' => 'PC-OTHER', 'title' => '남의 현장 계약',
            'status' => 'active', 'certified_payroll_required' => true,
        ]);
        $this->worker('우리 현장 사람');

        $this->assertFalse($this->check('AZ-01')['required']);
        $this->assertTrue($this->check('TX-02')['required']);
    }

    public function test_a_terminated_worker_is_not_counted(): void
    {
        $this->contract();
        $this->worker('현직');
        Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '퇴사자', 'role' => 'Electrician', 'employment_status' => 'terminated',
        ]);

        $this->assertSame(1, $this->check()['headcount']);
    }

    // ── 급여 배치와 대시보드 ──────────────────────────────────────────────

    public function test_the_payroll_dashboard_surfaces_the_requirement(): void
    {
        $this->contract();
        $this->worker('강민철');

        $dash = app(PayrollCalculator::class)->dashboard('2026-08-03', 'ALL');

        $this->assertTrue($dash['certifiedPayroll']['required']);
        $this->assertSame(1, $dash['certifiedPayroll']['headcount']);
    }

    public function test_the_dashboard_stays_quiet_when_nothing_requires_it(): void
    {
        $this->worker('강민철');

        $this->assertFalse(app(PayrollCalculator::class)->dashboard('2026-08-03', 'ALL')['certifiedPayroll']['required']);
    }

    public function test_the_run_records_the_judgement_it_made(): void
    {
        // 계약은 나중에 바뀐다. 돌린 시점의 판단이 배치에 남아야 감사 때
        // "왜 이때 WH-347 을 냈나/안 냈나" 에 답할 수 있다.
        $this->contract();
        $this->worker('강민철');

        $run = app(PayrollCalculator::class)->runPayroll('2026-08-03', 'ALL');

        $this->assertTrue($run->payload['certifiedPayroll']['required']);
        $this->assertSame('LG ESS 전기공사', $run->payload['certifiedPayroll']['sources'][0]['label']);
    }
}
