<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_work_records', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::create('claim_write_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('operation', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('request_hash', 64);
            $table->jsonb('response');
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'request_id']);
        });
        Schema::create('claim_draft_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_edit_draft_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('content');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['claim_edit_draft_id', 'revision']);
        });
        DB::table('claim_edit_drafts')->orderBy('id')->chunkById(100, function ($drafts): void {
            foreach ($drafts as $draft) {
                foreach (json_decode($draft->history ?? '[]', true) as $entry) {
                    DB::table('claim_draft_revisions')->insertOrIgnore([
                        'claim_edit_draft_id' => $draft->id, 'revision' => $entry['revision'],
                        'content' => json_encode($entry['content'], JSON_THROW_ON_ERROR),
                        'created_at' => $entry['at'] ?? $draft->created_at,
                    ]);
                }
                DB::table('claim_draft_revisions')->insertOrIgnore([
                    'claim_edit_draft_id' => $draft->id, 'revision' => $draft->revision,
                    'content' => $draft->content, 'record_id' => $draft->claim_work_record_id,
                    'created_at' => $draft->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_draft_revisions');
        Schema::dropIfExists('claim_write_receipts');
        Schema::table('claim_work_records', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
