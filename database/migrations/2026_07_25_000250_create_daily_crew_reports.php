<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_crew_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_contractor_id')->nullable()->constrained('site_contractors')->nullOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('attendance_qr_code_id')->nullable()->constrained('attendance_qr_codes')->nullOnDelete();
            $table->date('work_date')->index();
            $table->unsignedInteger('scanned_headcount')->default(0);
            $table->unsignedInteger('external_headcount')->default(0);
            $table->integer('manual_adjustment')->default(0);
            $table->unsignedInteger('final_headcount')->default(0);
            $table->string('status', 30)->default('closed')->index();
            $table->string('work_description', 500)->nullable();
            $table->string('adjustment_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reported_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'work_date']);
            $table->index(['site_id', 'work_date', 'status']);
            $table->index(['company_id', 'site_contractor_id', 'work_date'], 'daily_crew_company_context_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_crew_reports');
    }
};
