<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('site_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $t->uuid('upload_token');
            $t->string('title', 160);
            $t->date('meeting_on');
            $t->text('participants')->nullable();
            $t->string('status', 30)->default('uploading')->index();
            $t->string('disk', 40);
            $t->string('audio_path')->nullable();
            $t->string('audio_mime', 60);
            $t->string('audio_hash', 64);
            $t->unsignedInteger('audio_bytes');
            $t->unsignedSmallInteger('part_count');
            $t->jsonb('parts')->nullable();
            $t->jsonb('transcripts')->nullable();
            $t->jsonb('analysis')->nullable();
            $t->foreignId('ops_intake_batch_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->text('error')->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamps();
            $t->unique(['created_by_id', 'upload_token']);
            $t->index(['site_id', 'meeting_on']);
        });
        Schema::table('ops_intake_items', function (Blueprint $t) {
            $t->jsonb('meeting_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_items', fn (Blueprint $t) => $t->dropColumn('meeting_meta'));
        Schema::dropIfExists('ops_meetings');
    }
};
