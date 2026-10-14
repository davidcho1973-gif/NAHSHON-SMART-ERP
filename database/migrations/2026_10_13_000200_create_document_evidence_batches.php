<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_evidence_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('upload_session_key')->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('run_token')->nullable();
            $table->unsignedBigInteger('active_item_id')->nullable();
            $table->unsignedInteger('match_cursor')->default(0);
            $table->boolean('match_inflight')->default(false);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'status']);
            $table->unique(['created_by', 'upload_session_key']);
        });
        Schema::create('document_evidence_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('document_evidence_batches')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('intelligent_documents')->restrictOnDelete();
            $table->uuid('upload_key');
            $table->string('original_name');
            $table->string('original_sha256', 64);
            $table->string('status', 20)->default('waiting');
            $table->boolean('is_duplicate')->default(false);
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->unique(['batch_id', 'upload_key']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_evidence_batch_items');
        Schema::dropIfExists('document_evidence_batches');
    }
};
