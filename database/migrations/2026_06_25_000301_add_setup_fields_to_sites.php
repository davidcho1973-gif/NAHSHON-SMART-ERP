<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'setup_completed_at')) {
                    $table->timestamp('setup_completed_at')->nullable()->after('timezone');
                }
                if (! Schema::hasColumn('sites', 'manager_employee_id')) {
                    $table->foreignId('manager_employee_id')
                        ->nullable()
                        ->after('setup_completed_at')
                        ->constrained('employees')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (Schema::hasColumn('sites', 'manager_employee_id')) {
                    $table->dropForeign(['manager_employee_id']);
                    $table->dropColumn('manager_employee_id');
                }
                if (Schema::hasColumn('sites', 'setup_completed_at')) {
                    $table->dropColumn('setup_completed_at');
                }
            });
        }
    }
};
