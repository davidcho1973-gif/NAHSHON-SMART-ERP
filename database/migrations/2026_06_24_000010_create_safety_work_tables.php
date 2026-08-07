<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 작업안전관리 persistence (Cowork / Safety module).
 *
 * Moves the foreman-led work-safety flow off browser localStorage onto the server so
 * TBM signatures and close reports become shared, auditable, multi-device records.
 *
 *   safety_work_items        one work card (plan -> TBM -> close -> progress)
 *   safety_work_signatures   per-worker TBM sign-off (legal record)
 *   safety_work_issues       미조치 / 위험상황 / 아차사고 tracked per work card
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_work_items', function (Blueprint $table): void {
            $table->id();
            $table->string('work_code', 40)->unique();          // WRK-2605-001
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $table->string('project')->nullable();
            $table->string('location')->nullable();             // free-text work spot (JS "site")
            $table->string('title');
            $table->unsignedInteger('crew')->default(0);
            $table->string('unit', 20)->nullable();
            $table->decimal('planned_qty', 12, 2)->default(0);
            $table->decimal('done_qty', 12, 2)->default(0);
            $table->decimal('total_qty', 12, 2)->default(0);
            $table->unsignedInteger('progress')->default(0);
            $table->string('due_label', 60)->nullable();        // free text: 오늘 17:00 / 내일

            // 4-stage status machine
            $table->string('plan_status', 30)->default('미생성');
            $table->string('tbm_status', 30)->default('대기');
            $table->string('close_status', 30)->default('시작전');
            $table->string('progress_status', 30)->default('미분석');

            $table->text('work_text')->nullable();              // plan input
            $table->text('close_text')->nullable();             // actual completion report
            $table->json('plan_payload')->nullable();           // future AI plan (PHA/PTP/TBM)

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'plan_status']);
        });

        Schema::create('safety_work_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safety_work_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 60)->nullable();
            $table->boolean('signed')->default(false);
            $table->timestampTz('signed_at')->nullable();       // server-stamped legal record
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('safety_work_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safety_work_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('미조치');       // 미조치 / 위험상황 / 아차사고
            $table->text('body')->nullable();
            $table->string('owner', 120)->nullable();
            $table->string('status', 30)->default('조치중');      // 조치중 / 완료
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_work_issues');
        Schema::dropIfExists('safety_work_signatures');
        Schema::dropIfExists('safety_work_items');
    }
};
