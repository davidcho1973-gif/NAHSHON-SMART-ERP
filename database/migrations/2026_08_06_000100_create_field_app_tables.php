<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('weather', 40)->nullable();
            $table->string('temperature', 40)->nullable();
            $table->json('trades')->nullable();
            $table->string('work_title', 500)->nullable();
            $table->text('work_today')->nullable();
            $table->text('work_tomorrow')->nullable();
            $table->unsignedTinyInteger('progress_rate')->default(0);
            $table->boolean('tbm_completed')->default(false);
            $table->json('safety_checks')->nullable();
            $table->text('safety_notes')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'work_date']);
        });

        Schema::create('field_equipment_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('name');
            $table->string('type', 40)->nullable();
            $table->string('operator', 100)->nullable();
            $table->string('status', 20)->default('running');
            $table->timestamps();

            $table->index(['site_id', 'work_date']);
        });

        Schema::create('field_commute_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('worker_name', 100)->nullable();
            $table->string('type', 10);
            $table->timestampTz('scanned_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'work_date']);
        });

        Schema::create('field_drawings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('drawing_no', 40)->index();
            $table->string('title', 500);
            $table->string('category', 100)->nullable();
            $table->string('version', 20)->default('v1.0');
            $table->string('file_path')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->text('summary')->nullable();
            $table->json('specs')->nullable();
            $table->json('safety_notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('ai_model', 100)->nullable();
            $table->json('analysis')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('field_drawing_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_drawing_id')->constrained('field_drawings')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('sources')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_drawing_messages');
        Schema::dropIfExists('field_drawings');
        Schema::dropIfExists('field_commute_logs');
        Schema::dropIfExists('field_equipment_logs');
        Schema::dropIfExists('field_daily_reports');
    }
};
