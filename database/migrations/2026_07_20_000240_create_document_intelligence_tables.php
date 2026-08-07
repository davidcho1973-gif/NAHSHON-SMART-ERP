<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligent_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_contract_id')->nullable()->constrained('project_contracts')->nullOnDelete();
            $table->foreignId('supersedes_document_id')->nullable()->constrained('intelligent_documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 40)->default('dropzone')->index();
            $table->string('external_id')->nullable()->index();
            $table->string('disk', 60)->default('local');
            $table->string('file_path');
            $table->string('original_file_name');
            $table->string('stored_file_name');
            $table->string('mime_type', 160)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->char('sha256', 64)->index();

            $table->string('title')->nullable()->index();
            $table->string('category', 60)->default('general')->index();
            $table->string('document_type', 100)->default('other')->index();
            $table->string('discipline', 80)->nullable()->index();
            $table->string('direction', 30)->default('internal')->index();
            $table->string('document_number', 120)->nullable()->index();
            $table->string('revision', 40)->nullable()->index();
            $table->string('sender')->nullable();
            $table->json('recipients')->nullable();
            $table->string('status', 40)->default('received')->index();
            $table->string('confidentiality', 30)->default('internal')->index();

            $table->string('virtual_path')->nullable()->index();
            $table->json('folder_structure')->nullable();
            $table->json('tags')->nullable();
            $table->json('keywords')->nullable();
            $table->text('summary')->nullable();
            $table->json('key_facts')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->longText('search_text')->nullable();

            $table->date('document_date')->nullable()->index();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->date('response_due_on')->nullable()->index();
            $table->timestampTz('received_at')->nullable()->index();

            $table->string('ai_status', 40)->default('queued')->index();
            $table->string('ai_engine', 40)->nullable();
            $table->string('ai_model')->nullable();
            $table->decimal('ai_confidence', 5, 2)->nullable();
            $table->json('ai_payload')->nullable();
            $table->text('ai_error')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'project_id'], 'intelligent_documents_scope_idx');
            $table->index(['project_id', 'category', 'document_type'], 'intelligent_documents_project_type_idx');
            $table->index(['ai_status', 'received_at'], 'intelligent_documents_ai_status_idx');
        });

        DB::statement("CREATE INDEX intelligent_documents_search_gin_idx ON intelligent_documents USING GIN (to_tsvector('simple', coalesce(search_text, '')))");
        DB::statement('CREATE UNIQUE INDEX intelligent_documents_scope_hash_unique ON intelligent_documents (COALESCE(company_id, 0), COALESCE(site_id, 0), COALESCE(project_id, 0), sha256)');

        Schema::create('document_action_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intelligent_document_id')->constrained('intelligent_documents')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action_type', 60)->index();
            $table->string('related_module', 60)->nullable()->index();
            $table->string('severity', 20)->default('normal')->index();
            $table->string('status', 30)->default('open')->index();
            $table->string('title');
            $table->text('details')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('source_excerpt')->nullable();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('remind_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity', 'due_at'], 'document_action_queue_idx');
            $table->index(['company_id', 'site_id', 'status'], 'document_action_scope_idx');
        });

        Schema::create('unified_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('alert_code', 40)->unique();
            $table->string('fingerprint')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('source_module', 60)->index();
            $table->string('source_type', 120)->nullable();
            $table->string('source_id', 120)->nullable();
            $table->string('event_type', 80)->index();
            $table->string('severity', 20)->default('normal')->index();
            $table->string('status', 30)->default('unresolved')->index();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('assignee')->nullable();
            $table->string('action_url')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('last_detected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'status'], 'unified_alert_scope_idx');
            $table->index(['status', 'severity', 'due_at'], 'unified_alert_queue_idx');
            $table->index(['source_module', 'source_type', 'source_id'], 'unified_alert_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unified_alerts');
        Schema::dropIfExists('document_action_items');
        Schema::dropIfExists('intelligent_documents');
    }
};
