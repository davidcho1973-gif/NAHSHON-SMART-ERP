<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll run + payslip ledger.
 *
 *   payroll_runs   one bi-weekly batch for a site scope (state machine: open -> calculated -> approved -> paid -> closed)
 *   payslips       one immutable per-employee result, with wage rate / division SNAPSHOTTED at calc time
 *   payslip_lines  per-project / per-hour-type breakdown (feeds both job costing and certified payroll WH-347)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();             // PR-2026-0001
            $table->date('period_start')->index();
            $table->date('period_end');
            $table->date('pay_date')->nullable();
            $table->string('site_scope', 40)->default('ALL');
            $table->string('status', 20)->default('open');    // open|calculated|approved|paid|closed
            $table->decimal('fx_rate_krw', 12, 4)->nullable();
            $table->decimal('total_gross', 16, 2)->default(0);
            $table->decimal('total_net', 16, 2)->default(0);
            $table->unsignedInteger('headcount')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('calculated_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });

        Schema::create('payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            // ── Immutable snapshot (frozen at calc time; HR edits never alter past pay) ──
            $table->string('snap_pay_type', 20);
            $table->decimal('snap_base_rate', 12, 4);
            $table->string('snap_trade', 60)->nullable();
            $table->string('snap_division', 20)->nullable();

            // ── Aggregated hours / earnings ──
            $table->decimal('regular_hours', 9, 2)->default(0);
            $table->decimal('overtime_hours', 9, 2)->default(0);
            $table->decimal('doubletime_hours', 9, 2)->default(0);
            $table->decimal('applied_rate', 12, 4)->default(0);
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->decimal('fringe_pay', 14, 2)->default(0);   // prevailing-wage fringe
            $table->decimal('per_diem', 14, 2)->default(0);     // non-taxable

            // ── Deductions ──
            $table->decimal('fed_tax', 14, 2)->default(0);
            $table->decimal('state_tax', 14, 2)->default(0);
            $table->decimal('fica', 14, 2)->default(0);         // Social Security 6.2%
            $table->decimal('medicare', 14, 2)->default(0);     // 1.45%
            $table->decimal('retirement_401k', 14, 2)->default(0);
            $table->decimal('other_deduction', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);

            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('open_days')->default(0); // timesheets missing check-out
            $table->string('status', 20)->default('draft');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            // projects live in a legacy mock today; keep the column FK-less until the table exists.
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cost_code', 40)->nullable();
            $table->string('hour_type', 5);                      // REG | OT | DT
            $table->decimal('hours', 9, 2)->default(0);
            $table->decimal('rate_applied', 12, 4)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
