<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_drawing_revisions', fn (Blueprint $table) => $table->json('markups')->nullable());
    }

    public function down(): void
    {
        Schema::table('cad_drawing_revisions', fn (Blueprint $table) => $table->dropColumn('markups'));
    }
};
