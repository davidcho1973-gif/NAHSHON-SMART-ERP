<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_edit_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_boq_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('content');
            $table->jsonb('history')->nullable();
            $table->foreignId('claim_work_record_id')->nullable()->constrained();
            $table->timestampsTz();
            $table->unique(['contract_boq_line_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_edit_drafts');
    }
};
