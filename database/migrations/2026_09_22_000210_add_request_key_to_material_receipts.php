<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receipts', function (Blueprint $table): void {
            $table->string('request_key', 100)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('material_receipts', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
        });
    }
};
