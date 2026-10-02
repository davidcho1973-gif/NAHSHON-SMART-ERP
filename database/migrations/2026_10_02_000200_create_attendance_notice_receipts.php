<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_notice_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unique(['communication_message_id', 'employee_id'], 'attendance_notice_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_notice_receipts');
    }
};
