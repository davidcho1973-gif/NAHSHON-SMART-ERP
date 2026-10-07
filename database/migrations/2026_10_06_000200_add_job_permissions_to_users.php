<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('job_role')->nullable();
            $table->json('job_duties')->nullable();
            $table->json('job_permissions')->nullable();
            $table->json('job_site_ids')->nullable();
        });
        // No role guessing or identity changes: existing accounts remain under their reviewed legacy policy.
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['job_role', 'job_duties', 'job_permissions', 'job_site_ids']));
    }
};
