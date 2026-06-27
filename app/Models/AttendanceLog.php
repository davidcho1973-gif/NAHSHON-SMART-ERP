<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

class AttendanceLog extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // attendance_date는 event_at의 날짜와 항상 일치해야 한다(관리자 패널 직접 등록/수정 대비).
        static::saving(function (self $log): void {
            if ($log->event_at && empty($log->attendance_date)) {
                $log->attendance_date = Carbon::parse($log->event_at)->toDateString();
            }
        });

        // 출퇴근 → 급여 타임시트 유기적 연동: 어떤 경로의 출퇴근 기록이든
        // 저장/삭제되면 해당 일자의 payroll_timesheets를 자동 재계산한다.
        static::saved(fn (self $log) => self::syncTimesheet($log));
        static::deleted(fn (self $log) => self::syncTimesheet($log));
    }

    private static function syncTimesheet(self $log): void
    {
        if (! $log->employee_id || ! $log->attendance_date) {
            return;
        }

        try {
            app(\App\Services\Payroll\AttendanceTimesheetSync::class)
                ->syncDay((int) $log->employee_id, Carbon::parse($log->attendance_date)->toDateString());
        } catch (\Throwable $e) {
            // 급여 동기화 실패가 출퇴근 기록 자체를 막지 않도록 격리한다.
            report($e);
        }
    }

    protected $fillable = [
        'employee_id',
        'company_id',
        'site_id',
        'team_id',
        'photo_upload_id',
        'daily_work_assignment_id',
        'attendance_qr_code_id',
        'employee_badge_qr_token_id',
        'site_contractor_id',
        'employer_company_id',
        'recorded_by_id',
        'attendance_date',
        'event_type',
        'event_at',
        'source',
        'status',
        'approved_by_id',
        'approved_at',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'event_at' => 'datetime',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dailyWorkAssignment(): BelongsTo
    {
        return $this->belongsTo(DailyWorkAssignment::class);
    }

    public function attendanceQrCode(): BelongsTo
    {
        return $this->belongsTo(AttendanceQrCode::class);
    }

    public function employeeBadgeQrToken(): BelongsTo
    {
        return $this->belongsTo(EmployeeBadgeQrToken::class);
    }

    public function siteContractor(): BelongsTo
    {
        return $this->belongsTo(SiteContractor::class);
    }

    public function employerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'employer_company_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function photoUpload(): BelongsTo
    {
        return $this->belongsTo(PhotoUpload::class);
    }

    public function approvalHistories(): MorphMany
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }
}
