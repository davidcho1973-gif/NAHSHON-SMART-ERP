<?php

namespace App\Services\Payroll;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges the attendance module (attendance_logs) to the payroll module
 * (payroll_timesheets). For a given employee + day it derives worked minutes
 * from the day's clock-in/out events and upserts the matching timesheet row,
 * which the PayrollCalculator then reads as labor hours.
 *
 * This keeps the two databases organically in sync: any attendance write
 * (web portal, QR, GPS, NFC, or admin edit) recomputes that day's timesheet.
 */
class AttendanceTimesheetSync
{
    /** Standard regular minutes per day; minutes beyond this are overtime. */
    private const REGULAR_MINUTES_PER_DAY = 480; // 8h

    /** Unpaid lunch deducted once a day exceeds this worked duration. */
    private const LUNCH_THRESHOLD_MINUTES = 240; // 4h

    private const LUNCH_MINUTES = 60;

    /**
     * Recompute and upsert the timesheet for one employee on one date.
     * Returns null when there is no usable attendance (e.g. only rejected logs).
     */
    public function syncDay(int $employeeId, string $date): ?PayrollTimesheet
    {
        if (! Schema::hasTable('payroll_timesheets') || ! Schema::hasTable('attendance_logs')) {
            return null;
        }

        $logs = AttendanceLog::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $date)
            ->where('status', '!=', 'rejected')
            ->orderBy('event_at')
            ->get();

        if ($logs->isEmpty()) {
            // Attendance for the day was removed/rejected — drop any derived timesheet.
            PayrollTimesheet::query()
                ->where('employee_id', $employeeId)
                ->where('work_date', $date)
                ->where('source', 'attendance_logs')
                ->delete();

            return null;
        }

        $checkIn = $logs->firstWhere('event_type', 'clock_in')?->event_at;
        $checkOut = $logs->where('event_type', 'clock_out')->last()?->event_at;

        $regular = 0;
        $overtime = 0;
        $payable = 0;

        if ($checkIn && $checkOut) {
            $worked = Carbon::parse($checkIn)->diffInMinutes(Carbon::parse($checkOut));
            $payable = $worked > self::LUNCH_THRESHOLD_MINUTES ? $worked - self::LUNCH_MINUTES : $worked;
            $payable = max(0, $payable);
            $regular = min($payable, self::REGULAR_MINUTES_PER_DAY);
            $overtime = max(0, $payable - self::REGULAR_MINUTES_PER_DAY);
        }

        $employee = Employee::find($employeeId);

        return PayrollTimesheet::updateOrCreate(
            ['employee_id' => $employeeId, 'work_date' => $date],
            [
                'company_id' => $employee?->company_id,
                'site_id' => $employee?->site_id,
                'team_id' => $employee?->team_id,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'regular_minutes' => $regular,
                'overtime_minutes' => $overtime,
                'payable_minutes' => $payable,
                'status' => $checkOut ? 'approved' : 'draft',
                'source' => 'attendance_logs',
            ]
        );
    }

    /**
     * Backfill timesheets from all attendance within a date range.
     *
     * @return int number of (employee, day) timesheets synced
     */
    public function backfill(?string $from = null, ?string $to = null): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            return 0;
        }

        $pairs = AttendanceLog::query()
            ->where('status', '!=', 'rejected')
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to))
            ->get(['employee_id', 'attendance_date'])
            ->map(fn ($l) => $l->employee_id.'|'.Carbon::parse($l->attendance_date)->toDateString())
            ->unique();

        $count = 0;
        foreach ($pairs as $pair) {
            [$employeeId, $date] = explode('|', $pair, 2);
            $this->syncDay((int) $employeeId, $date);
            $count++;
        }

        return $count;
    }
}
