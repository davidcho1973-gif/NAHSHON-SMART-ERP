<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_drawing_revisions', fn (Blueprint $table) => $table->jsonb('workspace')->nullable());
        Schema::table('cad_drawings', function (Blueprint $table): void {
            $table->foreignId('copied_from_revision_id')->nullable()->constrained('cad_drawing_revisions')->restrictOnDelete();
            $table->uuid('copy_request_key')->nullable()->unique();
            $table->string('copy_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cad_drawings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('copied_from_revision_id');
            $table->dropColumn(['copy_request_key', 'copy_fingerprint']);
        });
        Schema::table('cad_drawing_revisions', fn (Blueprint $table) => $table->dropColumn('workspace'));
    }
};
