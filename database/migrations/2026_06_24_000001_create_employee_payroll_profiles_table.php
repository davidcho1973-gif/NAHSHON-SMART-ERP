<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll profile = the per-employee wage master that the payroll engine reads.
 *
 * Kept in a dedicated 1:1 table (not on `employees`) so the HR-owned `employees`
 * model stays untouched while payroll evolves independently. The payroll engine
 * snapshots these values onto each payslip at run time, so later edits here never
 * mutate historical pay (audit integrity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            // Wage basis
            $table->string('pay_type', 20)->default('hourly');   // hourly | salary | daily
            $table->decimal('base_rate', 12, 4)->default(0);      // hourly=$/h, salary=$/yr, daily=$/day
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5);
            $table->string('trade', 60)->nullable();              // Welder/Fitter/Rigger - prevailing-wage match key
            $table->string('worker_division', 20)->nullable();    // 관리자 | 한국인 | 외국인 (null => inferred)

            // Classification / dispatch (US Korean-vendor context)
            $table->boolean('is_exempt')->default(false);         // FLSA exempt => no overtime premium
            $table->boolean('is_dispatched')->default(false);     // Korea-dispatched worker
            $table->string('visa_type', 20)->nullable();          // L-1 / E-2 / B-1 / H-1B
            $table->char('pay_currency', 3)->default('USD');
            $table->decimal('per_diem_rate', 10, 2)->default(0);  // non-taxable daily allowance

            // Withholding setup
            $table->string('fed_filing_status', 20)->nullable();  // single | married
            $table->char('withholding_state', 2)->nullable();     // GA/TX/AZ/TN ...
            $table->decimal('retirement_pct', 5, 2)->default(0);  // 401k contribution %

            $table->date('effective_from')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_profiles');
    }
};
