<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_drawings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('source_format', 3);
            $table->string('original_name');
            $table->string('disk');
            $table->string('original_path');
            $table->string('sha256', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'updated_at']);
        });
        Schema::create('cad_drawing_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cad_drawing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('dxf_path');
            $table->string('sha256', 64);
            $table->json('warnings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['cad_drawing_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_drawing_revisions');
        Schema::dropIfExists('cad_drawings');
    }
};
