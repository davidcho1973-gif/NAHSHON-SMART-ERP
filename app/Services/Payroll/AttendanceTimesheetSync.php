<?php

namespace App\Services\Payroll;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Support\WorkRules;
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
    /**
     * Recompute and upsert the timesheet for one employee on one date.
     * Returns null when there is no usable attendance (e.g. only rejected logs).
     */
    public function syncDay(int $employeeId, string $date): ?PayrollTimesheet
    {
        if (! Schema::hasTable('payroll_timesheets') || ! Schema::hasTable('attendance_logs')) {
            return null;
        }

        // 시급/일급 직영만 시간 정산 대상이다. 협력사는 소속사가 임금을 지급하고(인원체크만),
        // 월급제 관리자는 정액이라 일별 근무시간이 급여에 쓰이지 않는다 — 출퇴근 원장은
        // 그대로 남기되 급여 타임시트는 만들지 않는다.
        $employee = Employee::find($employeeId);
        if ($employee && $employee->attendancePolicy() !== Employee::POLICY_HOURLY) {
            PayrollTimesheet::query()
                ->where('employee_id', $employeeId)
                ->where('work_date', $date)
                ->where('source', 'attendance_logs')
                ->delete();

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

        // 그날 실제로 찍힌 현장/팀이 직원 마스터의 소속보다 정확하다 — 파견·이동 근무가 있다.
        $context = $logs->last();

        $regular = 0;
        $overtime = 0;
        $payable = 0;

        if ($checkIn && $checkOut) {
            // Carbon 3 returns fractional minutes for second-precision punches.
            // Preserve whole-minute truncation before applying payroll rules;
            // PostgreSQL integer columns reject fractional-minute values.
            $worked = (int) Carbon::parse($checkIn)->diffInMinutes(Carbon::parse($checkOut));

            // 정규 시간·무급 휴게는 <b>현장마다 다르다</b>(여러 주에 흩어져 있고
            // 프로젝트마다 시간표가 다르다). 규칙은 WorkRules 한 곳에만 둔다 —
            // 여기와 화면이 각자 계산하면 같은 하루가 두 숫자가 된다.
            $split = WorkRules::forSite($context?->site_id ?: $employee?->site_id)->split($worked);

            $payable = $split['payable'];
            $regular = $split['regular'];
            $overtime = $split['overtime'];
        }

        return PayrollTimesheet::updateOrCreate(
            ['employee_id' => $employeeId, 'work_date' => $date],
            [
                'company_id' => $employee?->company_id,
                'site_id' => $context?->site_id ?: $employee?->site_id,
                'team_id' => $context?->team_id ?: $employee?->team_id,
                'site_contractor_id' => $context?->site_contractor_id,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'regular_minutes' => $regular,
                'overtime_minutes' => $overtime,
                'payable_minutes' => $payable,
                'status' => $checkOut ? 'approved' : 'draft',
                'source' => 'attendance_logs',
                // 어느 로그에서 나온 시간인지 — 급여 이의가 들어왔을 때 추적하는 근거.
                'payload' => ['attendance_log_ids' => $logs->pluck('id')->all()],
            ]
        );
    }

    /**
     * 한 사람의 출퇴근을 날짜별로 다시 계산한다.
     *
     * 이 표는 «출퇴근 기록» 과 «그 사람의 고용형태» 두 가지에서 나온다. 그런데
     * 지금까지는 기록이 바뀔 때만 다시 계산했다. 고용형태는 이 표가 있느냐 없느냐를
     * 정하는 값인데도 그쪽이 바뀌면 아무 일도 일어나지 않았다.
     *
     * 그래서 «소속 미확인» 으로 일한 날이 생기면(급여 시트를 만들지 않는다) 나중에
     * 인사에서 자사 직영으로 확인해도 그 며칠은 급여에서 통째로 빠진다 — 사람은
     * 일했고 기록도 남아 있는데 임금만 없다. 파생된 표는 자기 입력 <b>전부</b>를 따라야 한다.
     *
     * @return int 다시 계산한 (사람, 날짜) 수
     */
    public function resyncEmployee(int $employeeId, ?string $from = null, ?string $to = null): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            return 0;
        }

        $dates = AttendanceLog::query()
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 'rejected')
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to))
            ->pluck('attendance_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique();

        foreach ($dates as $date) {
            $this->syncDay($employeeId, $date);
        }

        return $dates->count();
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
