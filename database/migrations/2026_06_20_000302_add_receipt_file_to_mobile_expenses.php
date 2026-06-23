<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->string('receipt_mime_type', 120)->nullable()->after('receipt_path');
            $table->string('receipt_original_name')->nullable()->after('receipt_mime_type');
            $table->binary('receipt_file')->nullable()->after('receipt_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropColumn([
                'receipt_mime_type',
                'receipt_original_name',
                'receipt_file',
            ]);
        });
    }
};
