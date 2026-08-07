<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_contractors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('company_name');
            $table->string('contract_role', 80)->nullable()->index();
            $table->string('contract_number', 120)->nullable()->index();
            $table->text('scope_of_work')->nullable();
            $table->string('primary_contact_name', 120)->nullable();
            $table->string('primary_contact_phone', 80)->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->unique(['site_id', 'company_name', 'contract_number']);
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('contract_company_name')->nullable()->index();
            $table->string('trade_type', 80)->nullable()->index();
            $table->string('responsible_manager_name', 120)->nullable();
            $table->string('supervisor_name', 120)->nullable();
            $table->string('supervisor_phone', 80)->nullable();
            $table->unsignedInteger('planned_headcount')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'contract_company_name',
                'trade_type',
                'responsible_manager_name',
                'supervisor_name',
                'supervisor_phone',
                'planned_headcount',
                'notes',
            ]);
        });

        Schema::dropIfExists('site_contractors');
    }
};
