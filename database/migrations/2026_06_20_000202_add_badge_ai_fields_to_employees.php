<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('first_name', 120)->nullable()->after('badge_number');
            $table->string('last_name', 120)->nullable()->after('first_name');
            $table->string('badge_company_name')->nullable()->after('email');
            $table->date('badge_issued_on')->nullable()->after('badge_company_name');
            $table->string('badge_photo_path')->nullable()->after('badge_issued_on');
            $table->string('badge_analysis_model', 80)->nullable()->after('badge_photo_path');
            $table->timestamp('badge_analyzed_at')->nullable()->after('badge_analysis_model');
            $table->json('badge_analysis_payload')->nullable()->after('badge_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'badge_analysis_payload',
                'badge_analyzed_at',
                'badge_analysis_model',
                'badge_photo_path',
                'badge_issued_on',
                'badge_company_name',
                'last_name',
                'first_name',
            ]);
        });
    }
};
