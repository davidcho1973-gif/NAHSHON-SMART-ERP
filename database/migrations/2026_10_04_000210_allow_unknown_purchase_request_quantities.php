<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 3)->nullable()->change();
            $table->string('unit', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unknown values cannot safely be invented during rollback.
        if (\Illuminate\Support\Facades\DB::table('purchase_request_lines')->whereNull('quantity')->orWhereNull('unit')->exists()) {
            throw new RuntimeException('Resolve unknown purchase quantities before rollback.');
        }
        Schema::table('purchase_request_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 3)->nullable(false)->change();
            $table->string('unit', 32)->nullable(false)->change();
        });
    }
};
