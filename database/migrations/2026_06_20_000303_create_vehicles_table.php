<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('vehicle_code', 60)->unique();
            
            // Scope fields for access control (AGENTS.md §6-3)
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $table->string('plate_number', 40)->nullable()->index();
            $table->string('vehicle_type', 60)->nullable();
            $table->string('model', 100);
            $table->string('vendor', 100)->nullable();
            
            $table->date('rent_start')->nullable();
            $table->date('rent_end')->nullable();
            $table->date('insurance_expiry')->nullable();
            
            $table->unsignedInteger('current_mileage')->default(0);
            $table->unsignedInteger('next_oil_change_mileage')->default(0);
            $table->string('status', 30)->default('대기중')->index(); // '운행중', '정비중', '대기중'

            $table->string('photo_front')->nullable();
            $table->string('photo_rear')->nullable();
            $table->string('photo_left')->nullable();
            $table->string('photo_right')->nullable();
            $table->string('contract_path')->nullable();

            $table->string('registration_method', 40)->default('manual'); // 'manual', 'AI자동분석'
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
