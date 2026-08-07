<?php

namespace Tests\Unit;

use App\Models\EmployeePayrollProfile;
use App\Services\Payroll\PayrollCalculator;
use Tests\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private PayrollCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PayrollCalculator();
    }

    public function test_hourly_gross_applies_overtime_premium(): void
    {
        $profile = new EmployeePayrollProfile(['pay_type' => 'hourly', 'base_rate' => 50, 'overtime_multiplier' => 1.5]);

        $result = $this->calc->grossForEmployee($profile, regHours: 80, otHours: 10);

        // 80×50 + 10×50×1.5 = 4000 + 750
        $this->assertSame(4750.0, $result['gross']);
        $this->assertSame('hourly', $result['basis']);
        $this->assertSame(50.0, $result['displayRate']);
    }

    public function test_exempt_employee_gets_straight_time_on_overtime(): void
    {
        $profile = new EmployeePayrollProfile(['pay_type' => 'hourly', 'base_rate' => 50, 'is_exempt' => true]);

        $result = $this->calc->grossForEmployee($profile, regHours: 80, otHours: 10);

        // exempt → OT at 1.0×: 80×50 + 10×50 = 4500
        $this->assertSame(4500.0, $result['gross']);
    }

    public function test_salary_is_sliced_into_biweekly_and_reports_hourly_equivalent(): void
    {
        $profile = new EmployeePayrollProfile(['pay_type' => 'salary', 'base_rate' => 130000]);

        $result = $this->calc->grossForEmployee($profile, regHours: 80, otHours: 0);

        $this->assertSame(5000.0, $result['gross']);        // 130000 / 26
        $this->assertSame(62.5, $result['displayRate']);    // 130000 / 2080
        $this->assertSame('salary', $result['basis']);
    }

    public function test_daily_rate_converts_hours_to_days(): void
    {
        $profile = new EmployeePayrollProfile(['pay_type' => 'daily', 'base_rate' => 400]);

        $result = $this->calc->grossForEmployee($profile, regHours: 16, otHours: 0);

        $this->assertSame(800.0, $result['gross']); // 16h / 8 = 2 days × 400
    }

    public function test_missing_profile_yields_zero_without_error(): void
    {
        $result = $this->calc->grossForEmployee(null, regHours: 40, otHours: 5);

        $this->assertSame(0.0, $result['gross']);
        $this->assertSame('hourly', $result['basis']);
    }

    public function test_deductions_compute_fica_medicare_and_net(): void
    {
        $profile = new EmployeePayrollProfile([
            'fed_filing_status' => 'single',
            'withholding_state' => 'TX', // no state income tax
            'retirement_pct' => 5,
        ]);

        $d = $this->calc->deductionsFor(gross: 4750, ytdGrossBefore: 0, profile: $profile);

        $this->assertSame(294.5, $d['fica']);        // 4750 × 6.2%
        $this->assertSame(68.88, $d['medicare']);    // 4750 × 1.45%
        $this->assertSame(0.0, $d['stateTax']);      // TX
        $this->assertSame(237.5, $d['retirement']);  // 5%
        $this->assertGreaterThan(0, $d['fedTax']);
        $this->assertSame(
            round(4750 - $d['fica'] - $d['medicare'] - $d['fedTax'] - $d['stateTax'] - $d['retirement'], 2),
            $d['net']
        );
    }

    public function test_fica_respects_annual_wage_base_cap(): void
    {
        $profile = new EmployeePayrollProfile(['withholding_state' => 'TX']);

        // Already over the wage base for the year → no further FICA.
        $d = $this->calc->deductionsFor(gross: 5000, ytdGrossBefore: PayrollCalculator::FICA_WAGE_BASE + 1000, profile: $profile);

        $this->assertSame(0.0, $d['fica']);
    }

    public function test_resolve_period_is_fourteen_days(): void
    {
        $period = $this->calc->resolvePeriod('2026-06-15');

        $this->assertSame('2026-06-15', $period['start']->toDateString());
        $this->assertSame('2026-06-28', $period['end']->toDateString());
    }
}
