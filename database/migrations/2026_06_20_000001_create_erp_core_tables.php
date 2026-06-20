<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone', 60)->default('America/Phoenix');
            $table->string('status', 30)->default('active')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('foreman_name')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'code']);
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number', 80)->unique();
            $table->string('badge_number', 80)->nullable()->unique();
            $table->string('name');
            $table->string('nationality', 80)->nullable()->index();
            $table->string('role', 120)->nullable();
            $table->string('employment_status', 30)->default('active')->index();
            $table->date('visa_expires_on')->nullable();
            $table->date('safety_training_expires_on')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('companies');
    }
};
