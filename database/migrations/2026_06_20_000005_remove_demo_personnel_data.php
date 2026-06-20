<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $demoEmployeeNumbers = ['EMP-1001', 'EMP-1002', 'EMP-1003', 'EMP-1004', 'EMP-1005'];
        $demoCompanyCodes = ['AI-KOREA', 'LOCAL-UNION', 'M-SOL'];
        $demoSiteCodes = ['HFF-02', 'LGES-AZ', 'NV-05'];

        if (Schema::hasTable('smart_records')) {
            DB::table('smart_records')
                ->where('module', 'hr')
                ->delete();
        }

        if (Schema::hasTable('employees')) {
            DB::table('employees')
                ->whereIn('employee_number', $demoEmployeeNumbers)
                ->delete();
        }

        if (Schema::hasTable('companies')) {
            DB::table('companies')
                ->whereIn('code', $demoCompanyCodes)
                ->whereNotIn('id', function ($query): void {
                    $query->select('company_id')
                        ->from('employees')
                        ->whereNotNull('company_id');
                })
                ->delete();
        }

        if (Schema::hasTable('sites')) {
            $usedSiteIds = collect();

            if (Schema::hasTable('employees')) {
                $usedSiteIds = $usedSiteIds->merge(
                    DB::table('employees')->whereNotNull('site_id')->pluck('site_id')
                );
            }

            if (Schema::hasTable('member_registrations')) {
                $usedSiteIds = $usedSiteIds->merge(
                    DB::table('member_registrations')->whereNotNull('site_id')->pluck('site_id')
                );
            }

            $deletableSiteIds = DB::table('sites')
                ->whereIn('code', $demoSiteCodes)
                ->whereNotIn('id', $usedSiteIds->unique()->values()->all())
                ->pluck('id');

            if ($deletableSiteIds->isNotEmpty()) {
                if (Schema::hasTable('teams')) {
                    DB::table('teams')->whereIn('site_id', $deletableSiteIds)->delete();
                }

                DB::table('sites')->whereIn('id', $deletableSiteIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Demo data is intentionally not restored.
    }
};
