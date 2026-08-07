<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('project_code', 80)->unique();
            $table->string('name');
            $table->string('construction_type', 80)->index();
            $table->foreignId('end_client_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('project_stage', 40)->default('estimate')->index();

            $table->string('vendor_tier', 20)->nullable()->index();
            $table->foreignId('upper_contractor_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('epc_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('po_number', 120)->nullable()->index();
            $table->string('contract_type', 60)->nullable()->index();
            $table->text('scope_of_work')->nullable();
            $table->string('change_order_code_prefix', 80)->nullable();

            $table->string('site_address')->nullable();
            $table->string('state', 20)->nullable()->index();
            $table->string('jurisdiction', 120)->nullable();
            $table->string('sales_use_tax_code', 80)->nullable();
            $table->decimal('sales_use_tax_rate', 7, 4)->nullable();

            $table->date('ntp_date')->nullable();
            $table->date('mobilization_date')->nullable();
            $table->date('planned_completion_date')->nullable();
            $table->date('actual_completion_date')->nullable();
            $table->json('milestone_plan')->nullable();

            $table->decimal('contract_amount', 15, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('budget_labor_amount', 15, 2)->nullable();
            $table->decimal('budget_material_amount', 15, 2)->nullable();
            $table->decimal('budget_equipment_amount', 15, 2)->nullable();
            $table->decimal('budget_expense_amount', 15, 2)->nullable();
            $table->decimal('retainage_percent', 5, 2)->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->string('cost_code_system', 120)->nullable();
            $table->string('wbs_code', 120)->nullable()->index();

            $table->boolean('prevailing_wage_required')->default(false);
            $table->boolean('davis_bacon_required')->default(false);
            $table->string('union_status', 60)->nullable()->index();
            $table->boolean('certified_payroll_required')->default(false);
            $table->json('insurance_requirements')->nullable();
            $table->string('ocip_ccip_status', 60)->nullable();
            $table->boolean('bonding_required')->default(false);
            $table->string('osha_plan_status', 60)->nullable();
            $table->boolean('lien_notice_required')->default(false);
            $table->date('preliminary_notice_due_on')->nullable();

            $table->json('workforce_plan')->nullable();
            $table->json('korean_dispatch_plan')->nullable();
            $table->text('per_diem_policy')->nullable();
            $table->json('equipment_plan')->nullable();
            $table->json('subcontractor_plan')->nullable();
            $table->json('master_data_links')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'project_stage']);
            $table->index(['state', 'project_stage']);
            $table->index(['upper_contractor_company_id', 'vendor_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
