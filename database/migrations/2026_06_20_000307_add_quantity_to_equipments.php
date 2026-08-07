<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_bulk')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'is_bulk']);
        });
    }
};
