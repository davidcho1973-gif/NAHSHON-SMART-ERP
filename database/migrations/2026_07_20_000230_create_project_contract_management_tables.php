<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('counterparty_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('internal_reference', 80)->unique();
            $table->string('contract_number', 120)->nullable()->index();
            $table->string('title');
            $table->string('direction', 30)->default('receivable')->index();
            $table->string('counterparty_role', 60)->nullable()->index();
            $table->string('contract_type', 60)->default('prime_contract')->index();
            $table->string('status', 40)->default('draft')->index();
            $table->string('risk_level', 20)->default('low')->index();

            $table->date('executed_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable()->index();
            $table->date('notice_to_proceed_on')->nullable();
            $table->unsignedSmallInteger('renewal_notice_days')->default(60);
            $table->date('next_action_on')->nullable()->index();
            $table->text('next_action_notes')->nullable();

            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('approved_change_amount', 15, 2)->default(0);
            $table->decimal('current_amount', 15, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('retainage_percent', 5, 2)->nullable();
            $table->string('payment_terms', 120)->nullable();

            $table->boolean('insurance_required')->default(false);
            $table->boolean('bond_required')->default(false);
            $table->boolean('prevailing_wage_required')->default(false);
            $table->boolean('certified_payroll_required')->default(false);
            $table->boolean('lien_notice_required')->default(false);

            $table->string('counterparty_contact_name', 120)->nullable();
            $table->string('counterparty_contact_email')->nullable();
            $table->string('counterparty_contact_phone', 80)->nullable();
            $table->text('scope_of_work')->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'status'], 'project_contract_scope_status_idx');
            $table->index(['project_id', 'status'], 'project_contract_project_status_idx');
            $table->index(['counterparty_company_id', 'status'], 'project_contract_party_status_idx');
        });

        Schema::create('project_contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_contract_id')->constrained('project_contracts')->cascadeOnDelete();
            $table->foreignId('replaces_document_id')->nullable()->constrained('project_contract_documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('document_type', 80)->index();
            $table->string('title');
            $table->string('document_number', 120)->nullable()->index();
            $table->string('version', 40)->default('1.0');
            $table->string('status', 40)->default('draft')->index();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_current')->default(true)->index();
            $table->boolean('is_confidential')->default(true);

            $table->date('issued_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('disk', 40)->default('local');
            $table->string('file_path')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['project_contract_id', 'document_type', 'status'], 'contract_docs_type_status_idx');
            $table->index(['project_contract_id', 'is_current'], 'contract_docs_current_idx');
            $table->index(['status', 'expires_on'], 'contract_docs_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contract_documents');
        Schema::dropIfExists('project_contracts');
    }
};
