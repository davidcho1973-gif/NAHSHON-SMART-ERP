<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_ai_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_id')->constrained('cad_drawings')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('editor_token_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->boolean('allow_edits')->default(false);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'expires_at']);
        });
        Schema::create('cad_ai_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->constrained('cad_ai_sessions')->cascadeOnDelete();
            $table->string('oauth_client_id', 100);
            $table->uuid('oauth_family_id');
            $table->string('idempotency_key', 100);
            $table->string('document_hash', 64);
            $table->json('actions')->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('result')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
            $table->unique(['session_id', 'oauth_client_id', 'idempotency_key'], 'cad_ai_command_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_ai_commands');
        Schema::dropIfExists('cad_ai_sessions');
    }
};
