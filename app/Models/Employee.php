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
        'team_id',
        'employee_number',
        'badge_number',
        'name',
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
            'visa_expires_on' => 'date',
            'safety_training_expires_on' => 'date',
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
