<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Historical actor/scope IDs survive deletion; they never confer access.
            $table->unsignedBigInteger('created_by_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->string('operation', 48);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('payload');
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->string('record_version', 64)->nullable();
            $table->string('actor_context', 64);
            $table->string('version', 64);
            $table->string('preview_token', 64);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->json('result')->nullable();
            $table->json('audit_events');
            $table->timestampsTz();
            $table->index(['actor_id', 'status', 'created_at']);
            $table->index(['company_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_proposals');
    }
};
