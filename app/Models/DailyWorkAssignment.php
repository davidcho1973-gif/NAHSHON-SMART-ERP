<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyWorkAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'employer_company_id',
        'site_id',
        'site_contractor_id',
        'team_id',
        'status',
        'source',
        'assigned_by_id',
        'approved_by_id',
        'approved_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'employer_company_id');
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

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
