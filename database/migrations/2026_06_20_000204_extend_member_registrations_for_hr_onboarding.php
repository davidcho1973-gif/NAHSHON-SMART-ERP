<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->string('preferred_language', 5)->default('es')->index();
            $table->string('first_name', 120)->nullable()->index();
            $table->string('last_name', 120)->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 80)->nullable();
            $table->string('interview_status', 40)->default('pending')->index();
            $table->timestamp('interviewed_at')->nullable();
            $table->foreignId('interviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('interview_notes')->nullable();
            $table->string('safety_training_status', 40)->default('pending')->index();
            $table->date('safety_training_completed_on')->nullable();
            $table->string('badge_registration_status', 40)->default('pending')->index();
            $table->string('nfc_raw_uid', 120)->nullable()->index();
            $table->string('badge_photo_path')->nullable();
            $table->string('badge_company_name')->nullable();
            $table->string('badge_first_name', 120)->nullable();
            $table->string('badge_last_name', 120)->nullable();
            $table->string('badge_role', 120)->nullable();
            $table->date('badge_issued_on')->nullable();
            $table->string('badge_analysis_model', 80)->nullable();
            $table->timestamp('badge_analyzed_at')->nullable();
            $table->json('badge_analysis_payload')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('privacy_consent_language', 5)->nullable();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('start_date');
        });

        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('interviewed_by_id');
            $table->dropColumn([
                'preferred_language',
                'first_name',
                'last_name',
                'date_of_birth',
                'address',
                'emergency_contact_name',
                'emergency_contact_phone',
                'interview_status',
                'interviewed_at',
                'interview_notes',
                'safety_training_status',
                'safety_training_completed_on',
                'badge_registration_status',
                'nfc_raw_uid',
                'badge_photo_path',
                'badge_company_name',
                'badge_first_name',
                'badge_last_name',
                'badge_role',
                'badge_issued_on',
                'badge_analysis_model',
                'badge_analyzed_at',
                'badge_analysis_payload',
                'privacy_consent_at',
                'privacy_consent_language',
            ]);
        });
    }
};
