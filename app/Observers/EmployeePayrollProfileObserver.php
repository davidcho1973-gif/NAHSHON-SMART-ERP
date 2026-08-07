<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges HR onboarding to payroll: the moment an Employee is created (via the
 * applicant activation flow or a manual add), a wage profile is provisioned so the
 * worker immediately appears in payroll instead of being missing / $0.
 *
 * The rate starts at 0 — HR/payroll sets the real hourly/salary value afterwards
 * on the "임금 프로필 (Pay Profiles)" screen.
 */
class EmployeePayrollProfileObserver
{
    public function created(Employee $employee): void
    {
        if (! Schema::hasTable('employee_payroll_profiles')) {
            return;
        }

        try {
            EmployeePayrollProfile::query()->firstOrCreate(
                ['employee_id' => $employee->id],
                self::defaultsFor($employee),
            );
        } catch (\Throwable $exception) {
            // Never block employee creation if the profile can't be provisioned.
            report($exception);
        }
    }

    /**
     * Sensible starting values derived from what HR already captured on the employee.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(Employee $employee): array
    {
        $payload = is_array($employee->payload) ? $employee->payload : [];
        $nationality = mb_strtolower((string) $employee->nationality);

        $isDispatched = str_contains($nationality, 'korea')
            || str_contains($nationality, '한국')
            || ($payload['workerStatus'] ?? null) === '파견중';

        return [
            'company_id' => $employee->company_id,
            'site_id' => $employee->site_id,
            'pay_type' => 'hourly',
            'base_rate' => 0,
            'overtime_multiplier' => 1.5,
            'trade' => $employee->role,
            'is_dispatched' => $isDispatched,
            'visa_type' => $payload['visa'] ?? null,
            'pay_currency' => 'USD',
            'effective_from' => optional($employee->start_date)->toDateString() ?? now()->toDateString(),
        ];
    }
}
