<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wbs_photos', function (Blueprint $table): void {
            $table->string('original_path', 1024)->nullable();
            $table->string('original_sha256', 64)->nullable();
        });
        Schema::table('ops_intake_batches', fn (Blueprint $table) => $table->jsonb('original_photos')->nullable());
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', fn (Blueprint $table) => $table->dropColumn('original_photos'));
        Schema::table('wbs_photos', fn (Blueprint $table) => $table->dropColumn(['original_path', 'original_sha256']));
    }
};
