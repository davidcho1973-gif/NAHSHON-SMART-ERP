<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('pay_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 32)->default('prepared');
            $table->unsignedInteger('profile_version');
            $table->json('evidence');
            $table->json('profile_snapshot');
            $table->json('application_snapshot');
            $table->json('manifest');
            $table->string('disk');
            $table->string('file_path');
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->timestampTz('prepared_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('response_at')->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('sent_reference', 255)->nullable();
            $table->json('response_document')->nullable();
            $table->json('history');
            $table->timestampsTz();
            $table->unique(['pay_application_id', 'revision']);
            $table->index(['project_contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_submissions');
    }
};
