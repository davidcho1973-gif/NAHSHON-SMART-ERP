<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_library_parts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('category', 30);
            $table->string('specification', 100)->default('');
            $table->string('code', 80)->default('');
            $table->jsonb('geometry');
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_library_parts');
    }
};
