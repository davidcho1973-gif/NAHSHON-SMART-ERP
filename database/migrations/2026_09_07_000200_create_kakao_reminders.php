<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kakao_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->text('phone'); // encrypted E.164; never place this in delivery logs
            $table->boolean('enabled')->default(false);
            $table->timestampTz('consented_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('weekdays');
            $table->string('clock_in', 5)->nullable();
            $table->string('clock_out', 5)->nullable();
            $table->string('daily_report', 5)->nullable();
            $table->timestampsTz();
        });
        Schema::create('kakao_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('reason', 80)->nullable();
            $table->string('message_id', 120)->nullable();
            $table->string('group_id', 120)->nullable();
            $table->string('provider_code', 40)->nullable();
            $table->timestampsTz();
            $table->unique(['employee_id', 'work_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kakao_deliveries');
        Schema::dropIfExists('kakao_recipients');
    }
};
