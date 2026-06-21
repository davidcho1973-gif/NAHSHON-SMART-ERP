<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'team_id',
        'employee_number',
        'badge_number',
        'first_name',
        'last_name',
        'name',
        'email',
        'badge_company_name',
        'badge_issued_on',
        'badge_photo_path',
        'badge_analysis_model',
        'badge_analyzed_at',
        'badge_analysis_payload',
        'nationality',
        'role',
        'employment_status',
        'visa_expires_on',
        'safety_training_expires_on',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'badge_issued_on' => 'date',
            'badge_analyzed_at' => 'datetime',
            'badge_analysis_payload' => 'array',
            'visa_expires_on' => 'date',
            'safety_training_expires_on' => 'date',
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
