<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollRun;
use App\Models\PayrollTimesheet;
use App\Models\Payslip;
use App\Models\PayslipLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PayrollCalculator — the HR ⇄ Payroll engine.
 *
 * Pipeline:  employees (wage SSOT) + approved timesheets (labor hours)
 *            → per-employee gross (FLSA reg/OT) → deductions → net
 *            → bi-weekly dashboard read-model  /  persisted payslip ledger.
 *
 * Tax model note: FICA/Medicare are exact; federal/state withholding use a
 * documented percentage-method approximation (constants below). Swap in the
 * official IRS Pub 15-T / state tables when wiring real filing — the call sites
 * (`federalWithholding`, `stateWithholding`) are the only things that change.
 */
class PayrollCalculator
{
    /** Standard full-time hours used to derive an hourly-equivalent rate for salaried staff. */
    public const STANDARD_HOURS_PER_WEEK = 40;
    public const BIWEEKLY_PERIODS_PER_YEAR = 26;
    public const ANNUAL_WORK_HOURS = 2080; // 40h × 52w

    /** US payroll tax constants (2026 figures; adjust yearly). */
    public const FICA_RATE = 0.062;
    public const FICA_WAGE_BASE = 176100.0;
    public const MEDICARE_RATE = 0.0145;
    public const MEDICARE_ADDL_RATE = 0.009;
    public const MEDICARE_ADDL_THRESHOLD = 200000.0;

    /** Pay-period length. Anchored to a known Monday so every period is deterministic. */
    public const PERIOD_DAYS = 14;
    private const PERIOD_ANCHOR = '2026-01-05'; // a Monday

    /** Per-state flat withholding approximation. Default applies when a state is absent. */
    private const STATE_RATES = [
        'TX' => 0.0, 'TN' => 0.0, 'NV' => 0.0, 'FL' => 0.0, 'WA' => 0.0, 'SD' => 0.0, 'WY' => 0.0,
        'AZ' => 0.025, 'GA' => 0.0539, 'CA' => 0.06, 'NY' => 0.0685, 'OH' => 0.035, 'IN' => 0.0305,
    ];
    private const STATE_RATE_DEFAULT = 0.05;

    private const DIVISIONS = ['관리자', '한국인', '외국인'];

    /**
     * Build the bi-weekly payroll dashboard payload consumed by renderPayroll().
     * Always returns a fully-shaped structure (zeros, never undefined) so the SPA cannot crash.
     */
    public function dashboard(?string $periodStart = null, string $siteId = 'ALL'): array
    {
        $period = $this->resolvePeriod($periodStart);
        $rows = $this->aggregate($period['start'], $period['end'], $siteId);

        $totals = [
            'headcount' => $rows->count(),
            'regHours' => round((float) $rows->sum('regHours'), 1),
            'otHours' => round((float) $rows->sum('otHours'), 1),
            'gross' => round((float) $rows->sum('gross'), 2),
        ];

        return [
            'success' => true,
            'period' => [
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'currentDay' => $period['currentDay'],
                'totalDays' => self::PERIOD_DAYS,
                'isComplete' => $period['isComplete'],
            ],
            'totals' => $totals,
            'companies' => $this->companyMatrix($rows),
            'anomalies' => $this->anomalies($rows),
            'employees' => $rows->map(fn (array $r): array => [
                'badgeId' => $r['badgeId'],
                'name' => $r['name'],
                'company' => $r['company'],
                'divide' => $r['divide'],
                'regHours' => round($r['regHours'], 2),
                'otHours' => round($r['otHours'], 2),
                'rate' => round($r['displayRate'], 2),
                'basis' => $r['basis'],
                'gross' => round($r['gross'], 2),
                'openDays' => $r['openDays'],
            ])->values()->all(),
        ];
    }

    /**
     * Resolve the bi-weekly window that contains (or starts at) $periodStart.
     * Null → the period containing today, derived from the fixed anchor Monday.
     */
    public function resolvePeriod(?string $periodStart): array
    {
        $today = Carbon::today();

        if ($periodStart) {
            $start = Carbon::parse($periodStart)->startOfDay();
        } else {
            $anchor = Carbon::parse(self::PERIOD_ANCHOR);
            $elapsed = $anchor->diffInDays($today);
            $offset = intdiv($elapsed, self::PERIOD_DAYS) * self::PERIOD_DAYS;
            $start = $anchor->copy()->addDays($offset);
        }

        $end = $start->copy()->addDays(self::PERIOD_DAYS - 1);
        $isComplete = $today->gt($end);
        $currentDay = $today->lt($start)
            ? 0
            : min(self::PERIOD_DAYS, $start->diffInDays($today) + 1);

        return compact('start', 'end', 'currentDay', 'isComplete');
    }

    /**
     * Per-employee aggregation: active roster ⟕ timesheet hours in the period,
     * merged with each employee's wage profile and run through the gross calc.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function aggregate(Carbon $start, Carbon $end, string $siteId = 'ALL'): Collection
    {
        if (! Schema::hasTable('employees')) {
            return collect();
        }

        $employees = Employee::query()
            ->with(['company', 'site'])
            ->when($siteId !== 'ALL', fn ($q) => $q->whereHas('site', fn ($s) => $s->where('code', $siteId)))
            ->where(fn ($q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'terminated'))
            ->get();

        if ($employees->isEmpty()) {
            return collect();
        }

        $hours = $this->timesheetTotals($employees->pluck('id')->all(), $start, $end);
        $profiles = $this->profilesFor($employees->pluck('id')->all());

        return $employees->map(function (Employee $employee) use ($hours, $profiles): array {
            $profile = $profiles->get($employee->id);
            $h = $hours->get($employee->id, ['regHours' => 0.0, 'otHours' => 0.0, 'openDays' => 0]);

            $earn = $this->grossForEmployee($profile, $h['regHours'], $h['otHours'], 0.0);

            return [
                'employeeId' => $employee->id,
                'badgeId' => $employee->badge_number ?: $employee->employee_number,
                'name' => $employee->name,
                'company' => $employee->company?->name ?: 'Unassigned',
                'divide' => $this->divisionFor($employee, $profile),
                'regHours' => (float) $h['regHours'],
                'otHours' => (float) $h['otHours'],
                'openDays' => (int) $h['openDays'],
                'basis' => $earn['basis'],
                'displayRate' => $earn['displayRate'],
                'gross' => $earn['gross'],
            ];
        })->filter(fn (array $r) => $r['regHours'] > 0 || $r['otHours'] > 0 || $r['gross'] > 0 || $r['openDays'] > 0)
          ->sortByDesc('gross')
          ->values();
    }

    /**
     * Gross pay for one employee given hours. Pure & unit-tested.
     *
     * hourly: reg×rate + ot×rate×mult (+ dt×rate×2)
     * salary: fixed bi-weekly slice (annual ÷ 26); rate shown = annual ÷ 2080
     * daily : reg-hours treated as (hours ÷ 8) days × daily rate
     *
     * @param  EmployeePayrollProfile|null  $profile
     * @return array{gross: float, appliedRate: float, displayRate: float, basis: string}
     */
    public function grossForEmployee(?EmployeePayrollProfile $profile, float $regHours, float $otHours, float $dtHours = 0.0): array
    {
        $payType = $profile->pay_type ?? 'hourly';
        $rate = (float) ($profile->base_rate ?? 0);
        $mult = (float) ($profile->overtime_multiplier ?? 1.5);
        $exempt = (bool) ($profile->is_exempt ?? false);

        if ($payType === 'salary') {
            $gross = $rate / self::BIWEEKLY_PERIODS_PER_YEAR;
            $hourly = $rate > 0 ? $rate / self::ANNUAL_WORK_HOURS : 0.0;

            return ['gross' => round($gross, 2), 'appliedRate' => $hourly, 'displayRate' => $hourly, 'basis' => 'salary'];
        }

        if ($payType === 'daily') {
            $days = ($regHours + $otHours) / 8;
            $gross = $days * $rate;

            return ['gross' => round($gross, 2), 'appliedRate' => $rate, 'displayRate' => $rate, 'basis' => 'daily'];
        }

        // hourly (default). Exempt staff earn straight time on all hours.
        $otFactor = $exempt ? 1.0 : $mult;
        $gross = ($regHours * $rate) + ($otHours * $rate * $otFactor) + ($dtHours * $rate * 2);

        return ['gross' => round($gross, 2), 'appliedRate' => $rate, 'displayRate' => $rate, 'basis' => 'hourly'];
    }

    /**
     * Statutory + elective deductions on a period's gross.
     *
     * @param  EmployeePayrollProfile|null  $profile
     * @return array{fica: float, medicare: float, fedTax: float, stateTax: float, retirement: float, net: float}
     */
    public function deductionsFor(float $gross, float $ytdGrossBefore, ?EmployeePayrollProfile $profile): array
    {
        // FICA caps at the annual wage base — only the portion under the cap is taxed.
        $ficaBase = max(0.0, min($gross, self::FICA_WAGE_BASE - $ytdGrossBefore));
        $fica = round($ficaBase * self::FICA_RATE, 2);

        $medicare = $gross * self::MEDICARE_RATE;
        $ytdAfter = $ytdGrossBefore + $gross;
        if ($ytdAfter > self::MEDICARE_ADDL_THRESHOLD) {
            $overThreshold = min($gross, $ytdAfter - self::MEDICARE_ADDL_THRESHOLD);
            $medicare += $overThreshold * self::MEDICARE_ADDL_RATE;
        }
        $medicare = round($medicare, 2);

        $fedTax = $this->federalWithholding($gross, $profile->fed_filing_status ?? 'single');
        $stateTax = $this->stateWithholding($gross, $profile->withholding_state ?? null);

        $retirementPct = (float) ($profile->retirement_pct ?? 0);
        $retirement = round($gross * $retirementPct / 100, 2);

        $net = round($gross - $fica - $medicare - $fedTax - $stateTax - $retirement, 2);

        return [
            'fica' => $fica,
            'medicare' => $medicare,
            'fedTax' => $fedTax,
            'stateTax' => $stateTax,
            'retirement' => $retirement,
            'net' => $net,
        ];
    }

    /**
     * Persist a payroll run: snapshot wage rates, write payslips + per-hour-type lines,
     * accumulate YTD for FICA caps. Idempotent per (period, site) via run code.
     */
    public function runPayroll(?string $periodStart, string $siteId, ?int $userId = null): PayrollRun
    {
        $period = $this->resolvePeriod($periodStart);
        $rows = $this->aggregate($period['start'], $period['end'], $siteId);

        return DB::transaction(function () use ($period, $rows, $siteId, $userId): PayrollRun {
            $run = PayrollRun::query()->updateOrCreate(
                ['code' => $this->runCode($period['start'], $siteId)],
                [
                    'period_start' => $period['start']->toDateString(),
                    'period_end' => $period['end']->toDateString(),
                    'pay_date' => $period['end']->copy()->addDays(5)->toDateString(),
                    'site_scope' => $siteId,
                    'status' => 'calculated',
                    'created_by_id' => $userId,
                    'calculated_at' => Carbon::now(),
                ]
            );

            // Rebuild cleanly so re-running a period is deterministic.
            $run->payslips()->each(fn (Payslip $p) => $p->delete());

            $profiles = $this->profilesFor($rows->pluck('employeeId')->all());
            $ytd = $this->ytdGrossBefore($rows->pluck('employeeId')->all(), $period['start']);
            $totalGross = 0.0;
            $totalNet = 0.0;

            foreach ($rows as $row) {
                $profile = $profiles->get($row['employeeId']);
                $earn = $this->grossForEmployee($profile, $row['regHours'], $row['otHours'], 0.0);
                $ytdBefore = (float) ($ytd[$row['employeeId']] ?? 0);
                $ded = $this->deductionsFor($earn['gross'], $ytdBefore, $profile);

                $payslip = $run->payslips()->create([
                    'employee_id' => $row['employeeId'],
                    'company_id' => $profile->company_id ?? null,
                    'snap_pay_type' => $earn['basis'],
                    'snap_base_rate' => $profile->base_rate ?? 0,
                    'snap_trade' => $profile->trade ?? null,
                    'snap_division' => $row['divide'],
                    'regular_hours' => $row['regHours'],
                    'overtime_hours' => $row['otHours'],
                    'applied_rate' => $earn['appliedRate'],
                    'gross_pay' => $earn['gross'],
                    'per_diem' => (float) ($profile->per_diem_rate ?? 0) * $row['openDays'],
                    'fed_tax' => $ded['fedTax'],
                    'state_tax' => $ded['stateTax'],
                    'fica' => $ded['fica'],
                    'medicare' => $ded['medicare'],
                    'retirement_401k' => $ded['retirement'],
                    'net_pay' => $ded['net'],
                    'currency' => $profile->pay_currency ?? 'USD',
                    'open_days' => $row['openDays'],
                    'status' => 'calculated',
                ]);

                $this->writeLines($payslip, $row, $earn['appliedRate'], $profile);

                $totalGross += $earn['gross'];
                $totalNet += $ded['net'];
            }

            $run->update([
                'total_gross' => round($totalGross, 2),
                'total_net' => round($totalNet, 2),
                'headcount' => $rows->count(),
            ]);

            return $run->fresh('payslips');
        });
    }

    /**
     * Assemble a US DOL WH-347 certified-payroll dataset from a persisted run:
     * per-worker daily hours across the period + classification, rate, gross and deductions.
     *
     * @return array{run: PayrollRun, dates: array<int,string>, rows: array<int,array<string,mixed>>}
     */
    public function certifiedPayrollData(PayrollRun $run): array
    {
        $run->loadMissing('payslips');
        $employeeIds = $run->payslips->pluck('employee_id')->all();

        $employees = Employee::query()->whereIn('id', $employeeIds)->with('company')->get()->keyBy('id');

        $sheets = Schema::hasTable('payroll_timesheets')
            ? PayrollTimesheet::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('work_date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->where('status', '!=', 'rejected')
                ->get()
                ->groupBy('employee_id')
            : collect();

        $dates = [];
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $rows = $run->payslips->map(function (Payslip $ps) use ($employees, $sheets, $dates): array {
            $employee = $employees->get($ps->employee_id);
            $byDate = ($sheets->get($ps->employee_id) ?? collect())
                ->keyBy(fn (PayrollTimesheet $s) => $s->work_date->toDateString());

            $daily = [];
            foreach ($dates as $dt) {
                $sheet = $byDate->get($dt);
                $daily[$dt] = $sheet ? round(((int) $sheet->regular_minutes + (int) $sheet->overtime_minutes) / 60, 2) : 0.0;
            }

            return [
                'badgeId' => $employee?->badge_number ?: $employee?->employee_number ?: '-',
                'name' => $employee?->name ?: '-',
                'classification' => $ps->snap_trade ?: ($employee?->role ?: '-'),
                'division' => $ps->snap_division ?: '-',
                'daily' => $daily,
                'regHours' => (float) $ps->regular_hours,
                'otHours' => (float) $ps->overtime_hours,
                'totalHours' => round((float) $ps->regular_hours + (float) $ps->overtime_hours, 2),
                'rate' => (float) $ps->applied_rate,
                'gross' => (float) $ps->gross_pay,
                'fica' => (float) $ps->fica,
                'medicare' => (float) $ps->medicare,
                'fedTax' => (float) $ps->fed_tax,
                'stateTax' => (float) $ps->state_tax,
                'retirement' => (float) $ps->retirement_401k,
                'net' => (float) $ps->net_pay,
            ];
        })->all();

        return ['run' => $run, 'dates' => $dates, 'rows' => $rows];
    }

    // ───────────────────────── internals ─────────────────────────

    /** @return Collection<int, array{regHours: float, otHours: float, openDays: int}> */
    private function timesheetTotals(array $employeeIds, Carbon $start, Carbon $end): Collection
    {
        if ($employeeIds === [] || ! Schema::hasTable('payroll_timesheets')) {
            return collect();
        }

        return PayrollTimesheet::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'rejected')
            ->get(['employee_id', 'regular_minutes', 'overtime_minutes', 'check_in_at', 'check_out_at'])
            ->groupBy('employee_id')
            ->map(fn (Collection $sheets): array => [
                'regHours' => round($sheets->sum('regular_minutes') / 60, 2),
                'otHours' => round($sheets->sum('overtime_minutes') / 60, 2),
                'openDays' => $sheets->filter(fn ($s) => $s->check_in_at && ! $s->check_out_at)->count(),
            ]);
    }

    /** @return Collection<int, EmployeePayrollProfile> keyed by employee_id */
    private function profilesFor(array $employeeIds): Collection
    {
        if ($employeeIds === [] || ! Schema::hasTable('employee_payroll_profiles')) {
            return collect();
        }

        return EmployeePayrollProfile::query()
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');
    }

    /** Year-to-date gross per employee before the current period (for FICA cap / addl medicare). */
    private function ytdGrossBefore(array $employeeIds, Carbon $periodStart): array
    {
        if ($employeeIds === [] || ! Schema::hasTable('payslips')) {
            return [];
        }

        return Payslip::query()
            ->whereIn('payslips.employee_id', $employeeIds)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereYear('payroll_runs.period_start', $periodStart->year)
            ->where('payroll_runs.period_start', '<', $periodStart->toDateString())
            ->groupBy('payslips.employee_id')
            ->selectRaw('payslips.employee_id, SUM(payslips.gross_pay) as ytd')
            ->pluck('ytd', 'payslips.employee_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function writeLines(Payslip $payslip, array $row, float $rate, ?EmployeePayrollProfile $profile): void
    {
        $siteId = $profile->site_id ?? null;

        if ($row['regHours'] > 0) {
            $payslip->lines()->create([
                'site_id' => $siteId,
                'hour_type' => 'REG',
                'hours' => $row['regHours'],
                'rate_applied' => $rate,
                'amount' => round($row['regHours'] * $rate, 2),
            ]);
        }

        if ($row['otHours'] > 0) {
            $otRate = $rate * (float) ($profile->overtime_multiplier ?? 1.5);
            $payslip->lines()->create([
                'site_id' => $siteId,
                'hour_type' => 'OT',
                'hours' => $row['otHours'],
                'rate_applied' => $otRate,
                'amount' => round($row['otHours'] * $otRate, 2),
            ]);
        }
    }

    /** @param Collection<int, array<string,mixed>> $rows */
    private function companyMatrix(Collection $rows): array
    {
        return $rows->groupBy('company')->map(function (Collection $group, string $name): array {
            $divides = [];
            foreach (self::DIVISIONS as $d) {
                $members = $group->where('divide', $d);
                $divides[$d] = [
                    'count' => $members->count(),
                    'hours' => round((float) $members->sum('regHours') + (float) $members->sum('otHours'), 1),
                    'gross' => round((float) $members->sum('gross'), 2),
                ];
            }

            return [
                'name' => $name,
                'totals' => [
                    'count' => $group->count(),
                    'regHours' => round((float) $group->sum('regHours'), 1),
                    'otHours' => round((float) $group->sum('otHours'), 1),
                    'gross' => round((float) $group->sum('gross'), 2),
                ],
                'divides' => $divides,
            ];
        })->sortByDesc(fn (array $c) => $c['totals']['gross'])->values()->all();
    }

    /** @param Collection<int, array<string,mixed>> $rows */
    private function anomalies(Collection $rows): array
    {
        return $rows->filter(fn (array $r) => $r['openDays'] > 0)
            ->map(fn (array $r): array => [
                'badgeId' => $r['badgeId'],
                'name' => $r['name'],
                'company' => $r['company'],
                'type' => '미체크아웃',
                'severity' => 'high',
                'reason' => 'Check-out 누락',
                'detail' => $r['openDays'] . '일',
            ])->values()->all();
    }

    private function divisionFor(Employee $employee, ?EmployeePayrollProfile $profile): string
    {
        if ($profile?->worker_division && in_array($profile->worker_division, self::DIVISIONS, true)) {
            return $profile->worker_division;
        }

        $role = mb_strtolower((string) $employee->role);
        foreach (['foreman', 'engineer', 'manager', 'supervisor', 'superintendent', 'inspector', 'pm', '관리'] as $needle) {
            if ($role !== '' && str_contains($role, $needle)) {
                return '관리자';
            }
        }

        $nationality = mb_strtolower((string) $employee->nationality);
        if ($profile?->is_dispatched || str_contains($nationality, 'korea') || str_contains($nationality, '한국') || str_contains($nationality, 'kr')) {
            return '한국인';
        }

        return '외국인';
    }

    /**
     * Federal withholding — annualized percentage-method approximation (2026 brackets,
     * std deduction baked in). Placeholder for IRS Pub 15-T; isolated for easy replacement.
     */
    private function federalWithholding(float $periodGross, string $filingStatus): float
    {
        $annual = $periodGross * self::BIWEEKLY_PERIODS_PER_YEAR;
        $married = str_starts_with(mb_strtolower($filingStatus), 'married');

        $stdDeduction = $married ? 30000.0 : 15000.0;
        $taxable = max(0.0, $annual - $stdDeduction);

        // (rate, lower-bound of bracket) on taxable income, single; doubled bands for married.
        $brackets = $married
            ? [[0.37, 751600], [0.35, 501050], [0.32, 394600], [0.24, 206700], [0.22, 96950], [0.12, 23850], [0.10, 0]]
            : [[0.37, 626350], [0.35, 250525], [0.32, 197300], [0.24, 103350], [0.22, 48475], [0.12, 11925], [0.10, 0]];

        $tax = 0.0;
        $upper = $taxable;
        foreach ($brackets as [$rate, $lower]) {
            if ($upper > $lower) {
                $tax += ($upper - $lower) * $rate;
                $upper = $lower;
            }
        }

        return round($tax / self::BIWEEKLY_PERIODS_PER_YEAR, 2);
    }

    private function stateWithholding(float $periodGross, ?string $state): float
    {
        $rate = $state !== null
            ? (self::STATE_RATES[strtoupper($state)] ?? self::STATE_RATE_DEFAULT)
            : self::STATE_RATE_DEFAULT;

        return round($periodGross * $rate, 2);
    }

    private function runCode(Carbon $start, string $siteId): string
    {
        return sprintf('PR-%s-%s', $start->format('Ymd'), strtoupper(substr($siteId, 0, 6)));
    }
}
