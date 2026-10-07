<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->unsignedSmallInteger('interval_hours')->default(24);
            $table->boolean('enabled')->default(false);
            $table->char('access_context', 64);
            $table->char('approval_version', 64);
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('next_run_at')->nullable()->index();
            $table->timestampTz('last_run_at')->nullable();
            $table->jsonb('last_result')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'site_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_checks');
    }
};
