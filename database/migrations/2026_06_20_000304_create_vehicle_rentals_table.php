<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_rentals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            
            // Scope fields for access control (AGENTS.md §6-3)
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('rented_at');
            $table->timestamp('returned_at')->nullable();
            
            $table->unsignedInteger('start_mileage')->nullable();
            $table->unsignedInteger('end_mileage')->nullable();
            
            $table->string('status', 30)->default('active')->index(); // 'active', 'returned'
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_rentals');
    }
};
