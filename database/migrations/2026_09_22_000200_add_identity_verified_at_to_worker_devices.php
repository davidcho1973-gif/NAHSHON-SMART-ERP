<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_devices', function (Blueprint $table) {
            // Legacy name/last-four selection was not proof of identity. Do not backfill.
            $table->timestamp('identity_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('worker_devices', fn (Blueprint $table) => $table->dropColumn('identity_verified_at'));
    }
};
