<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sections', function (Blueprint $table): void {
            $table->string('claim_trade')->nullable()->index();
        });
        Schema::create('field_claim_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_boq_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('intelligent_document_id')->constrained()->restrictOnDelete();
            $table->string('sha256', 64);
            $table->string('original_name');
            $table->timestamps();
            $table->unique(['contract_boq_line_id', 'user_id', 'intelligent_document_id'], 'field_claim_attachment_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_claim_attachments');
        Schema::table('work_sections', fn (Blueprint $table) => $table->dropColumn('claim_trade'));
    }
};
