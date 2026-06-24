<?php

use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Observers\EmployeePayrollProfileObserver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Provision a payroll wage profile for every existing employee that doesn't have
 * one yet, so the HR ⇄ Payroll link applies retroactively (not only to new hires).
 * Idempotent — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('employee_payroll_profiles')) {
            return;
        }

        Employee::query()
            ->whereNotIn('id', EmployeePayrollProfile::query()->select('employee_id'))
            ->orderBy('id')
            ->chunkById(200, function ($employees): void {
                foreach ($employees as $employee) {
                    EmployeePayrollProfile::query()->firstOrCreate(
                        ['employee_id' => $employee->id],
                        EmployeePayrollProfileObserver::defaultsFor($employee),
                    );
                }
            });
    }

    public function down(): void
    {
        // Wage data is intentionally retained on rollback.
    }
};
