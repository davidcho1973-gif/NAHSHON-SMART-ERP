<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        foreach (['email', 'badge_number', 'first_name', 'last_name', 'badge_company_name', 'nationality', 'role'] as $column) {
            if (! Schema::hasColumn('employees', $column)) {
                continue;
            }

            DB::table('employees')
                ->where($column, '')
                ->update([$column => null]);
        }
    }

    public function down(): void
    {
        //
    }
};
