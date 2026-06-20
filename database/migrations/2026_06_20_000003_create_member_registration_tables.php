<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_number', 80)->unique();
            $table->string('employee_number', 80)->nullable()->unique();
            $table->string('badge_number', 80)->nullable()->unique();
            $table->string('member_type', 40)->default('worker')->index();
            $table->string('full_name');
            $table->string('preferred_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('nationality', 80)->nullable()->index();
            $table->string('role', 120)->nullable();
            $table->string('trade', 120)->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('visa_type', 60)->nullable();
            $table->date('visa_expires_on')->nullable()->index();
            $table->date('safety_training_expires_on')->nullable()->index();
            $table->string('identity_status', 40)->default('pending')->index();
            $table->string('document_status', 40)->default('missing')->index();
            $table->string('onboarding_status', 40)->default('draft')->index();
            $table->unsignedTinyInteger('automation_score')->default(0);
            $table->string('risk_level', 20)->default('medium')->index();
            $table->uuid('invite_token')->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('member_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_registration_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 80)->index();
            $table->string('title');
            $table->string('status', 40)->default('pending')->index();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('file_path')->nullable();
            $table->json('extracted_data')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('member_registrations');
    }
};
