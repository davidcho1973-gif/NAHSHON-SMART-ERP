<?php

use App\Services\Payroll\AttendanceTimesheetSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time backfill so existing attendance feeds payroll on deploy.
     * Idempotent (upsert by employee + work_date); new attendance syncs
     * automatically via AttendanceLog model events going forward.
     */
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs') || ! Schema::hasTable('payroll_timesheets')) {
            return;
        }

        try {
            app(AttendanceTimesheetSync::class)->backfill();
        } catch (\Throwable $e) {
            // Never fail a deploy because of the backfill; it can be re-run via
            // `php artisan payroll:sync-timesheets`.
            report($e);
        }
    }

    public function down(): void
    {
        // No structural change to revert.
    }
};
