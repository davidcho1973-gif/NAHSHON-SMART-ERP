<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Takeoff estimates are mutable; accepted commercial terms are a distinct fact.
        Schema::create('contract_boq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_contract_id')->constrained('project_contracts')->restrictOnDelete();
            $table->string('line_no', 80);
            $table->text('description');
            $table->string('unit', 20);
            $table->decimal('contract_qty', 16, 4);
            $table->decimal('unit_price', 16, 4);
            $table->string('recognition_basis', 20)->default('quantity');
            $table->json('stage_weights')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('acceptance_note')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('intelligent_documents')->restrictOnDelete();
            $table->string('source_locator', 255)->nullable();
            $table->string('source_ref', 160)->nullable();
            $table->timestampsTz();
            $table->unique(['project_contract_id', 'line_no']);
            $table->unique(['project_contract_id', 'source_ref']);
        });
        Schema::create('claim_work_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_boq_line_id')->constrained('contract_boq_lines')->restrictOnDelete();
            $table->string('record_kind', 20);
            $table->date('work_date');
            $table->string('location', 255);
            $table->string('stage', 60)->default('installed');
            $table->decimal('reported_qty', 16, 4);
            $table->decimal('verified_qty', 16, 4)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->json('review_history')->nullable();
            $table->string('source_ref', 160)->nullable();
            $table->timestampsTz();
            $table->unique(['contract_boq_line_id', 'source_ref']);
            $table->index(['contract_boq_line_id', 'status', 'work_date'], 'claim_work_line_status_date_idx');
        });
        Schema::create('pay_application_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pay_application_id')->constrained('pay_applications')->cascadeOnDelete();
            $table->foreignId('claim_work_record_id')->constrained('claim_work_records')->restrictOnDelete();
            $table->decimal('quantity', 16, 4);
            $table->decimal('amount', 15, 2);
            $table->json('snapshot');
            $table->timestampsTz();
            $table->unique(['pay_application_id', 'claim_work_record_id'], 'claim_allocation_app_record_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_application_allocations');
        Schema::dropIfExists('claim_work_records');
        Schema::dropIfExists('contract_boq_lines');
    }
};
