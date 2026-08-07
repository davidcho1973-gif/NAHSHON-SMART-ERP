<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\DailyWorkAssignment;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Models\PayrollTimesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AttendanceQrService
{
    public const DUPLICATE_WINDOW_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function recordSelfScan(User $user, AttendanceQrCode $qrCode, string $mode = 'auto'): array
    {
        $employee = $this->activeEmployeeForUser($user);

        return $this->recordAttendance(
            employee: $employee,
            qrCode: $qrCode,
            recordedBy: $user,
            source: 'self_team_qr',
            mode: $mode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function recordForemanBadgeScan(User $user, AttendanceQrCode $qrCode, EmployeeBadgeQrToken $badgeToken, string $mode = 'auto', ?string $reason = null): array
    {
        $this->assertCanProcessCrew($user, $qrCode);

        $employee = $badgeToken->employee;
        if (! $employee || $employee->employment_status !== 'active') {
            throw new RuntimeException('Active worker was not found for this badge QR.');
        }

        return $this->recordAttendance(
            employee: $employee,
            qrCode: $qrCode,
            recordedBy: $user,
            source: 'foreman_badge_qr',
            mode: $mode,
            badgeToken: $badgeToken,
            reason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function recordAttendance(
        Employee $employee,
        AttendanceQrCode $qrCode,
        User $recordedBy,
        string $source,
        string $mode = 'auto',
        ?EmployeeBadgeQrToken $badgeToken = null,
        ?string $reason = null,
    ): array {
        if ($employee->employment_status !== 'active') {
            throw new RuntimeException('Only active employees can be recorded for attendance.');
        }

        $eventTime = Carbon::now($qrCode->site?->timezone ?: config('app.timezone'));
        $workDate = $eventTime->toDateString();

        return DB::transaction(function () use ($employee, $qrCode, $recordedBy, $source, $mode, $badgeToken, $reason, $eventTime, $workDate): array {
            $duplicate = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('event_at', '>=', $eventTime->copy()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
                ->where('event_at', '<=', $eventTime)
                ->where('status', '!=', 'rejected')
                ->latest('event_at')
                ->first();

            if ($duplicate) {
                return [
                    'success' => true,
                    'ignored' => true,
                    'message' => 'Duplicate scan ignored.',
                    'employee' => $employee,
                    'event_type' => $duplicate->event_type,
                    'event_at' => $duplicate->event_at,
                    'status' => $duplicate->status,
                ];
            }

            $assignmentStatus = $this->assignmentStatus($employee, $qrCode);
            $assignment = $this->findOrCreateAssignment($employee, $qrCode, $recordedBy, $assignmentStatus, $source, $workDate, $reason);
            $eventType = $this->resolveEventType($employee, $workDate, $mode);
            $lastTodayLog = $this->lastTodayLog($employee, $workDate);

            if ($eventType === 'clock_in' && $lastTodayLog?->event_type === 'clock_in') {
                return [
                    'success' => true,
                    'ignored' => true,
                    'message' => 'Worker is already clocked in.',
                    'employee' => $employee,
                    'event_type' => 'clock_in',
                    'event_at' => $lastTodayLog->event_at,
                    'status' => $lastTodayLog->status,
                ];
            }

            if ($eventType === 'clock_out' && $lastTodayLog?->event_type !== 'clock_in') {
                throw new RuntimeException('Clock-out requires an open clock-in record first.');
            }

            $log = AttendanceLog::query()->create([
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'site_id' => $qrCode->site_id,
                'team_id' => $qrCode->team_id,
                'photo_upload_id' => null,
                'daily_work_assignment_id' => $assignment->id,
                'attendance_qr_code_id' => $qrCode->id,
                'employee_badge_qr_token_id' => $badgeToken?->id,
                'site_contractor_id' => $qrCode->site_contractor_id,
                'employer_company_id' => $this->employerCompanyId(),
                'recorded_by_id' => $recordedBy->id,
                'attendance_date' => $workDate,
                'event_type' => $eventType,
                'event_at' => $eventTime,
                'source' => $source,
                'status' => $assignmentStatus,
                'notes' => $assignmentStatus === 'pending'
                    ? 'QR attendance saved as pending because worker default contractor does not match the QR work context.'
                    : 'QR attendance recorded.',
                'payload' => array_filter([
                    'qr_code_id' => $qrCode->id,
                    'badge_token_id' => $badgeToken?->id,
                    'recorded_by_employee_id' => $recordedBy->employee_id,
                    'reason' => $reason,
                    'mode' => $mode,
                ]),
            ]);

            if ($log->status === 'approved') {
                $this->syncTimesheetFor($employee, $workDate);
            }

            return [
                'success' => true,
                'ignored' => false,
                'message' => $eventType === 'clock_in' ? 'Clock-in recorded.' : 'Clock-out recorded.',
                'employee' => $employee,
                'assignment' => $assignment,
                'log' => $log,
                'event_type' => $eventType,
                'event_at' => $eventTime,
                'status' => $assignmentStatus,
            ];
        });
    }

    public function approveLog(AttendanceLog $log, ?User $user = null): AttendanceLog
    {
        $log->forceFill([
            'status' => 'approved',
            'approved_by_id' => $user?->id,
            'approved_at' => now(),
        ])->save();

        if ($log->dailyWorkAssignment) {
            $log->dailyWorkAssignment->forceFill([
                'status' => 'approved',
                'approved_by_id' => $user?->id,
                'approved_at' => now(),
            ])->save();
        }

        $this->syncTimesheetFor($log->employee, $log->attendance_date?->toDateString() ?: now()->toDateString());

        return $log;
    }

    public function rejectLog(AttendanceLog $log): AttendanceLog
    {
        $log->forceFill(['status' => 'rejected'])->save();
        $this->syncTimesheetFor($log->employee, $log->attendance_date?->toDateString() ?: now()->toDateString());

        return $log;
    }

    public function syncTimesheetFor(Employee $employee, string $workDate): ?PayrollTimesheet
    {
        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $workDate)
            ->where('status', 'approved')
            ->orderBy('event_at')
            ->get();

        if ($logs->isEmpty()) {
            PayrollTimesheet::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $workDate)
                ->delete();

            return null;
        }

        $firstIn = $logs->firstWhere('event_type', 'clock_in');
        $lastOut = $logs->where('event_type', 'clock_out')->last();
        $context = $logs->last() ?: $logs->first();

        $regularMinutes = 0;
        $overtimeMinutes = 0;
        $payableMinutes = 0;

        if ($firstIn && $lastOut && $lastOut->event_at->gt($firstIn->event_at)) {
            $payableMinutes = $firstIn->event_at->diffInMinutes($lastOut->event_at);
            $regularMinutes = min($payableMinutes, 480);
            $overtimeMinutes = max(0, $payableMinutes - 480);
        }

        return PayrollTimesheet::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'work_date' => $workDate,
            ],
            [
                'company_id' => $employee->company_id,
                'site_id' => $context?->site_id,
                'team_id' => $context?->team_id,
                'employer_company_id' => $this->employerCompanyId(),
                'site_contractor_id' => $context?->site_contractor_id,
                'check_in_at' => $firstIn?->event_at,
                'check_out_at' => $lastOut?->event_at,
                'regular_minutes' => $regularMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'payable_minutes' => $payableMinutes,
                'status' => $firstIn && $lastOut ? 'approved' : 'open',
                'source' => 'attendance_qr',
                'approved_by_id' => $context?->approved_by_id ?: $context?->recorded_by_id,
                'approved_at' => $context?->approved_at,
                'payload' => [
                    'attendance_log_ids' => $logs->pluck('id')->all(),
                    'source' => 'qr_attendance_app',
                ],
            ],
        );
    }

    public function canProcessCrew(User $user, ?AttendanceQrCode $qrCode = null): bool
    {
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'foreman'], true)) {
            return $this->withinUserScope($user, $qrCode);
        }

        $role = $user->employee?->attendance_app_role;

        return in_array($role, ['foreman', 'safety_manager', 'attendance_admin'], true)
            && $this->withinUserScope($user, $qrCode);
    }

    private function activeEmployeeForUser(User $user): Employee
    {
        $employee = $user->employee;

        if (! $employee || $employee->employment_status !== 'active') {
            throw new RuntimeException('Your login is not connected to an active employee.');
        }

        return $employee;
    }

    private function assertCanProcessCrew(User $user, AttendanceQrCode $qrCode): void
    {
        if (! $this->canProcessCrew($user, $qrCode)) {
            throw new RuntimeException('You do not have permission to process crew attendance for this QR.');
        }
    }

    private function withinUserScope(User $user, ?AttendanceQrCode $qrCode): bool
    {
        if ($qrCode === null || in_array($user->access_role, ['super_admin', 'admin', 'hr_manager'], true)) {
            return true;
        }

        if ($user->access_scope === 'all_sites') {
            return true;
        }

        if ($user->allowed_site_id && (int) $user->allowed_site_id !== (int) $qrCode->site_id) {
            return false;
        }

        if ($user->allowed_team_id && (int) $user->allowed_team_id !== (int) $qrCode->team_id) {
            return false;
        }

        $employee = $user->employee;
        $scope = $employee?->attendance_app_scope ?: $user->access_scope ?: 'self';

        if ($scope === 'all_sites') {
            return true;
        }

        if ($scope === 'site') {
            return $employee?->site_id ? (int) $employee->site_id === (int) $qrCode->site_id : (bool) $user->allowed_site_id;
        }

        if ($scope === 'team') {
            return $employee?->team_id ? (int) $employee->team_id === (int) $qrCode->team_id : (bool) $user->allowed_team_id;
        }

        return false;
    }

    private function assignmentStatus(Employee $employee, AttendanceQrCode $qrCode): string
    {
        $contractorCompanyId = $qrCode->siteContractor?->company_id ?: $qrCode->team?->company_id;

        if (! $contractorCompanyId || ! $employee->company_id) {
            return 'pending';
        }

        return (int) $employee->company_id === (int) $contractorCompanyId ? 'approved' : 'pending';
    }

    private function findOrCreateAssignment(Employee $employee, AttendanceQrCode $qrCode, User $assignedBy, string $status, string $source, string $workDate, ?string $reason): DailyWorkAssignment
    {
        $assignment = DailyWorkAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->where('site_id', $qrCode->site_id)
            ->where('team_id', $qrCode->team_id)
            ->where('site_contractor_id', $qrCode->site_contractor_id)
            ->first();

        if ($assignment) {
            if ($assignment->status !== 'approved' && $status === 'approved') {
                $assignment->forceFill([
                    'status' => 'approved',
                    'approved_by_id' => $assignedBy->id,
                    'approved_at' => now(),
                ])->save();
            }

            return $assignment;
        }

        return DailyWorkAssignment::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'employer_company_id' => $this->employerCompanyId(),
            'site_id' => $qrCode->site_id,
            'site_contractor_id' => $qrCode->site_contractor_id,
            'team_id' => $qrCode->team_id,
            'status' => $status,
            'source' => $source,
            'assigned_by_id' => $assignedBy->id,
            'approved_by_id' => $status === 'approved' ? $assignedBy->id : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'payload' => array_filter([
                'default_company_id' => $employee->company_id,
                'qr_contractor_company_id' => $qrCode->siteContractor?->company_id ?: $qrCode->team?->company_id,
                'reason' => $reason,
            ]),
        ]);
    }

    private function resolveEventType(Employee $employee, string $workDate, string $mode): string
    {
        $mode = Str::lower($mode);

        if (in_array($mode, ['clock_in', 'clock_out'], true)) {
            return $mode;
        }

        $lastLog = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->latest('event_at')
            ->first();

        return $lastLog?->event_type === 'clock_in' ? 'clock_out' : 'clock_in';
    }

    private function lastTodayLog(Employee $employee, string $workDate): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->latest('event_at')
            ->first();
    }

    private function employerCompanyId(): ?int
    {
        return Company::query()
            ->where(fn ($query) => $query
                ->whereRaw('lower(code) in (?, ?)', ['nahshon-mep', 'nahshon'])
                ->orWhereRaw('lower(name) in (?, ?)', ['nahshon mep', 'nahshon-mep'])
                ->orWhereRaw('lower(legal_name) in (?, ?)', ['nahshon mep', 'nahshon-mep']))
            ->value('id');
    }
}
