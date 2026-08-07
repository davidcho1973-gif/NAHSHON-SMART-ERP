<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipments', function (Blueprint $table): void {
            $table->id();
            $table->string('equipment_code', 60)->unique();
            
            // Scope / Assignment fields
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('equipment_type', 100);
            $table->string('model', 100);
            $table->string('vendor', 100)->nullable();
            
            $table->date('rent_start')->nullable();
            $table->date('rent_end')->nullable();
            
            $table->unsignedInteger('daily_rate')->default(0);
            $table->unsignedInteger('delivery_fee')->default(0);
            $table->string('status', 30)->default('대기중')->index(); // '대기중', '사용중', '정비중'

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
        Schema::dropIfExists('equipments');
    }
};
