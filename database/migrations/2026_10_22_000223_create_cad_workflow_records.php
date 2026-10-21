<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_locations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('cad_drawing_id')->constrained()->restrictOnDelete();
            $t->foreignId('source_revision_id')->constrained('cad_drawing_revisions')->restrictOnDelete();
            $t->string('markup_id', 80);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['cad_drawing_id', 'markup_id']);
        });
        Schema::create('cad_location_links', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('cad_location_id')->constrained()->restrictOnDelete();
            $t->string('target_type', 24);
            $t->unsignedBigInteger('target_id');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at');
            $t->timestampTz('retired_at')->nullable();
            $t->unique(['cad_location_id', 'target_type', 'target_id'], 'cad_link_target_unique');
            $t->index(['target_type', 'target_id']);
        });
        Schema::create('cad_tasks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('cad_location_id')->constrained()->restrictOnDelete();
            $t->string('kind', 24);
            $t->string('title');
            $t->text('details');
            $t->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $t->date('due_on');
            $t->string('status', 24)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->text('resolution')->nullable();
            $t->jsonb('evidence')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->index(['assigned_user_id', 'status', 'due_on']);
        });
        Schema::create('cad_purchase_drafts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('cad_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->jsonb('content');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('purchase_request_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('cad_drawing_packets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('cad_drawing_revision_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->string('disk');
            $t->string('path');
            $t->string('pdf_path');
            $t->string('sha256', 64);
            $t->string('fingerprint', 64);
            $t->jsonb('manifest');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at');
        });
        Schema::create('cad_workflow_events', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('request_key')->unique();
            $t->foreignId('cad_drawing_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('cad_location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 40);
            $t->jsonb('payload');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['cad_workflow_events', 'cad_drawing_packets', 'cad_purchase_drafts', 'cad_tasks', 'cad_location_links', 'cad_locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
