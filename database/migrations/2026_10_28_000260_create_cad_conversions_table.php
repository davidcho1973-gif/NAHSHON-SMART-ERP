<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('site_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('operation', 16);
            $table->string('status', 16)->default('queued');
            $table->string('title');
            $table->string('original_name');
            $table->string('disk');
            $table->string('source_path');
            $table->string('source_format', 8);
            $table->string('sha256', 64);
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'request_key']);
            $table->index(['site_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_conversions');
    }
};
