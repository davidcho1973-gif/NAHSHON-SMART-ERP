<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_studios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drawing_sheet_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->jsonb('document');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('drawing_studio_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drawing_studio_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('document');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['drawing_studio_id', 'revision']);
        });
        Schema::create('drawing_share_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('drawing_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('destination');
            $table->string('status', 24);
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_share_dispatches');
        Schema::dropIfExists('drawing_studio_revisions');
        Schema::dropIfExists('drawing_studios');
    }
};
