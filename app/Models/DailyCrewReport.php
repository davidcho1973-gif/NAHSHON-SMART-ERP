<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCrewReport extends Model
{
    use HasFactory;

    public const STATUS_OPTIONS = [
        'closed' => '마감 완료 (Closed)',
        'reopened' => '재검토 (Reopened)',
    ];

    protected $fillable = [
        'company_id',
        'site_id',
        'site_contractor_id',
        'team_id',
        'attendance_qr_code_id',
        'work_date',
        'scanned_headcount',
        'external_headcount',
        'manual_adjustment',
        'final_headcount',
        'status',
        'work_description',
        'adjustment_reason',
        'notes',
        'reported_by_id',
        'reported_at',
        'closed_by_id',
        'closed_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scanned_headcount' => 'integer',
            'external_headcount' => 'integer',
            'manual_adjustment' => 'integer',
            'final_headcount' => 'integer',
            'reported_at' => 'datetime',
            'closed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $report): void {
            $report->scanned_headcount = max(0, (int) $report->scanned_headcount);
            $report->external_headcount = max(0, (int) $report->external_headcount);
            $report->manual_adjustment = (int) $report->manual_adjustment;
            $report->final_headcount = max(
                0,
                $report->scanned_headcount + $report->external_headcount + $report->manual_adjustment,
            );
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function siteContractor(): BelongsTo
    {
        return $this->belongsTo(SiteContractor::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function attendanceQrCode(): BelongsTo
    {
        return $this->belongsTo(AttendanceQrCode::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}
