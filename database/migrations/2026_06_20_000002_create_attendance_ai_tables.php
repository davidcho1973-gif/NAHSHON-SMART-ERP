<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capture_type', 40)->index();
            $table->timestampTz('captured_at')->nullable()->index();
            $table->timestampTz('uploaded_at')->nullable()->index();
            $table->string('storage_disk', 60)->nullable();
            $table->string('storage_path')->nullable();
            $table->text('public_url')->nullable();
            $table->string('status', 40)->default('uploaded')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ocr_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('photo_upload_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 80)->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->longText('raw_text')->nullable();
            $table->json('badge_numbers')->nullable();
            $table->json('employee_matches')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('photo_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->date('attendance_date')->index();
            $table->string('event_type', 40)->index();
            $table->timestampTz('event_at')->index();
            $table->string('source', 40)->default('central_control')->index();
            $table->string('status', 40)->default('pending')->index();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'attendance_date']);
            $table->index(['site_id', 'team_id', 'attendance_date']);
        });

        Schema::create('approval_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->string('action', 60)->index();
            $table->string('previous_status', 60)->nullable();
            $table->string('new_status', 60)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
        });

        Schema::create('payroll_timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date')->index();
            $table->timestampTz('check_in_at')->nullable();
            $table->timestampTz('check_out_at')->nullable();
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('payable_minutes')->default(0);
            $table->string('status', 40)->default('draft')->index();
            $table->string('source', 40)->default('attendance_logs')->index();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_type', 80)->index();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });

        Schema::create('ai_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('output_type', 80)->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->longText('text')->nullable();
            $table->json('structured_data')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_outputs');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('payroll_timesheets');
        Schema::dropIfExists('approval_histories');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('ocr_results');
        Schema::dropIfExists('photo_uploads');
    }
};
