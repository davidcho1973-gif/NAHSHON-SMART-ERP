<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_record_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_edit_draft_id')->constrained()->restrictOnDelete();
            $table->foreignId('mail_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_record_dispatches');
    }
};
