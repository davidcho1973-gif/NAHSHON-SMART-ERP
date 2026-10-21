<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drawing_share_dispatches', function (Blueprint $t): void {
            $t->unsignedBigInteger('drawing_sheet_id')->nullable()->change();
            $t->foreignUuid('cad_drawing_packet_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Historical CAD dispatches must not be deleted to restore a non-null PDF-sheet key.
        Schema::table('drawing_share_dispatches', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('cad_drawing_packet_id');
            $t->dropColumn('fingerprint');
        });
    }
};
