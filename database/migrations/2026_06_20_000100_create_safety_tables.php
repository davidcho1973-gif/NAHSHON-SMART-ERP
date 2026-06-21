<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 안전점검 (Safety Inspections)
        Schema::create('safety_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('title');
            $table->string('type', 40)->default('routine')->index();
            $table->string('status', 30)->default('scheduled')->index();
            $table->string('severity', 20)->nullable()->index();
            $table->date('scheduled_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('findings')->nullable();
            $table->text('corrective_action')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        // 사고/아차사고 보고 (Safety Incidents)
        Schema::create('safety_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('title');
            $table->string('category', 40)->default('near_miss')->index();
            $table->string('severity', 20)->default('low')->index();
            $table->string('status', 30)->default('open')->index();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('action_taken')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_incidents');
        Schema::dropIfExists('safety_inspections');
    }
};
