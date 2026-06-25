<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AttendanceLog extends Model
{
    use HasFactory;

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
