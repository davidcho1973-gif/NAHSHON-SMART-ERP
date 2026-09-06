<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_questions', function (Blueprint $table): void {
            // Old replies remain stored, but are not redisplayed under an unverified permission context.
            $table->string('access_context', 64)->nullable();
            $table->json('source_document_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_questions', fn (Blueprint $table) => $table->dropColumn(['access_context', 'source_document_ids']));
    }
};
