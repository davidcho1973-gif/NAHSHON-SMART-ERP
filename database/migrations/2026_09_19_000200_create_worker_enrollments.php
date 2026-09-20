<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phone/PIN accounts have no email; never invent deliverable addresses.
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        Schema::create('worker_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('phone', 20);
            $table->string('status', 20)->default('pending');
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_enrollments');
        // Keep email nullable: reverting must not destroy email-less employee accounts.
    }
};
