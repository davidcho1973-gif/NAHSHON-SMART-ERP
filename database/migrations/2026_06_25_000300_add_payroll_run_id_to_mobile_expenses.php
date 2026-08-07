<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_expenses')) {
            Schema::table('mobile_expenses', function (Blueprint $table): void {
                if (! Schema::hasColumn('mobile_expenses', 'payroll_run_id')) {
                    $table->foreignId('payroll_run_id')
                        ->nullable()
                        ->after('ocr_data')
                        ->constrained('payroll_runs')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_expenses')) {
            Schema::table('mobile_expenses', function (Blueprint $table): void {
                if (Schema::hasColumn('mobile_expenses', 'payroll_run_id')) {
                    $table->dropForeign(['payroll_run_id']);
                    $table->dropColumn('payroll_run_id');
                }
            });
        }
    }
};
