<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_requests', function (Blueprint $table): void {
            $table->id();
            // Audit IDs deliberately survive account removal; no question, prompt or key.
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('feature', 40);
            $table->unsignedInteger('input_bytes');
            $table->unsignedInteger('max_output_tokens');
            $table->string('status', 20)->default('reserved');
            $table->timestampTz('reserved_at');
            $table->timestampTz('completed_at')->nullable();
            $table->index(['company_id', 'reserved_at']);
            $table->index(['company_id', 'user_id', 'reserved_at'], 'ai_assistant_actor_reserved_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_requests');
    }
};
