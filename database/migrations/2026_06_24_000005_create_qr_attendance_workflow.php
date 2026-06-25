<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_contractor_id')->nullable()->constrained('site_contractors')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('name');
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('mode', 40)->default('auto')->index();
            $table->string('status', 40)->default('active')->index();
            $table->boolean('require_gps')->default(false);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'team_id', 'status']);
        });

        Schema::create('employee_badge_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 40)->default('active')->index();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('daily_work_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->foreignId('employer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('site_contractor_id')->nullable()->constrained('site_contractors')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('status', 40)->default('approved')->index();
            $table->string('source', 40)->default('qr_scan')->index();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date', 'status']);
            $table->index(['site_id', 'site_contractor_id', 'team_id', 'work_date'], 'daily_work_site_context_idx');
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->foreignId('daily_work_assignment_id')->nullable()->constrained('daily_work_assignments')->nullOnDelete();
            $table->foreignId('attendance_qr_code_id')->nullable()->constrained('attendance_qr_codes')->nullOnDelete();
            $table->foreignId('employee_badge_qr_token_id')->nullable()->constrained('employee_badge_qr_tokens')->nullOnDelete();
            $table->foreignId('site_contractor_id')->nullable()->constrained('site_contractors')->nullOnDelete();
            $table->foreignId('employer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('payroll_timesheets', function (Blueprint $table): void {
            $table->foreignId('employer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_contractor_id')->nullable()->constrained('site_contractors')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('attendance_app_role', 40)->default('worker')->index();
            $table->string('attendance_app_scope', 40)->default('self')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['attendance_app_role', 'attendance_app_scope']);
        });

        Schema::table('payroll_timesheets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_contractor_id');
            $table->dropConstrainedForeignId('employer_company_id');
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by_id');
            $table->dropConstrainedForeignId('employer_company_id');
            $table->dropConstrainedForeignId('site_contractor_id');
            $table->dropConstrainedForeignId('employee_badge_qr_token_id');
            $table->dropConstrainedForeignId('attendance_qr_code_id');
            $table->dropConstrainedForeignId('daily_work_assignment_id');
        });

        Schema::dropIfExists('daily_work_assignments');
        Schema::dropIfExists('employee_badge_qr_tokens');
        Schema::dropIfExists('attendance_qr_codes');
    }
};
