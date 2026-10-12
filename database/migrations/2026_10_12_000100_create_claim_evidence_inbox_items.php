<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_evidence_inbox_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_contract_id')->constrained('project_contracts')->restrictOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 20)->default('review');
            $table->string('classification', 20)->default('lines');
            $table->json('line_ids')->nullable();
            $table->json('locators')->nullable();
            $table->date('source_date')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('source_sha256', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('last_matched_at')->nullable();
            $table->json('review_history')->nullable();
            $table->timestampsTz();
            $table->unique(['project_contract_id', 'source_type', 'source_id'], 'claim_inbox_source_unique');
            $table->index(['source_type', 'source_id'], 'claim_inbox_source_index');
            $table->index(['project_contract_id', 'status', 'source_date'], 'claim_inbox_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_evidence_inbox_items');
    }
};
