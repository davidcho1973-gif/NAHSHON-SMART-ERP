<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('provider', 30)->default('microsoft');
            $table->string('provider_user_id')->nullable();
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->json('selected_folders')->nullable();
            $table->json('sync_cursor')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('email_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('provider_thread_id');
            $table->string('subject')->nullable();
            $table->json('participants')->nullable();
            $table->timestampTz('first_message_at')->nullable();
            $table->timestampTz('last_message_at')->nullable()->index();
            $table->text('summary_ko')->nullable();
            $table->string('classification', 80)->nullable();
            $table->boolean('needs_response')->default(false)->index();
            $table->date('response_due_on')->nullable()->index();
            $table->decimal('ai_confidence', 5, 2)->nullable();
            $table->string('visibility', 20)->default('private')->index();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('shared_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['mailbox_connection_id', 'provider_thread_id'], 'email_threads_provider_unique');
            $table->index(['owner_user_id', 'visibility', 'last_message_at'], 'email_threads_owner_visibility_idx');
        });

        Schema::table('intelligent_documents', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->foreignId('email_thread_id')->nullable()->after('external_id')->constrained('email_threads')->nullOnDelete();
            $table->string('access_level', 20)->default('scope')->after('confidentiality')->index();
        });

        // Private mailbox files must never collapse into another user's file merely because
        // the attachment bytes and project scope match.
        DB::statement('DROP INDEX IF EXISTS intelligent_documents_scope_hash_unique');
        DB::statement("CREATE UNIQUE INDEX intelligent_documents_scope_hash_unique ON intelligent_documents (COALESCE(company_id, 0), COALESCE(site_id, 0), COALESCE(project_id, 0), COALESCE(owner_user_id, 0), access_level, sha256)");

        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligent_document_id')->nullable()->constrained('intelligent_documents')->nullOnDelete();
            $table->string('provider_message_id');
            $table->string('internet_message_id')->nullable();
            $table->string('direction', 20)->default('incoming');
            $table->string('sender')->nullable();
            $table->json('recipients')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable()->index();
            $table->text('body_preview')->nullable();
            $table->string('raw_disk')->nullable();
            $table->string('raw_path')->nullable();
            $table->string('raw_sha256', 64)->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->timestamps();
            $table->unique(['mailbox_connection_id', 'provider_message_id'], 'email_messages_provider_unique');
            $table->index(['email_thread_id', 'sent_at'], 'email_messages_thread_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
        DB::statement('DROP INDEX IF EXISTS intelligent_documents_scope_hash_unique');
        Schema::table('intelligent_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_thread_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn('access_level');
        });
        DB::statement('CREATE UNIQUE INDEX intelligent_documents_scope_hash_unique ON intelligent_documents (COALESCE(company_id, 0), COALESCE(site_id, 0), COALESCE(project_id, 0), sha256)');
        Schema::dropIfExists('email_threads');
        Schema::dropIfExists('mailbox_connections');
    }
};
