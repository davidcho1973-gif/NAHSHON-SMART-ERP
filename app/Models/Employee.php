<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'team_id',
        'employee_number',
        'badge_number',
        'badge_printed_number',
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
        'start_date',
        'employment_status',
        'visa_expires_on',
        'safety_training_expires_on',
        'attendance_app_role',
        'attendance_app_scope',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'badge_issued_on' => 'date',
            'badge_analyzed_at' => 'datetime',
            'badge_analysis_payload' => 'array',
            'start_date' => 'date',
            'visa_expires_on' => 'date',
            'safety_training_expires_on' => 'date',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $employee->normalizeOptionalTextFields();

            if (blank($employee->employee_number)) {
                $employee->employee_number = self::makeEmployeeNumber();
            } else {
                $employee->employee_number = trim((string) $employee->employee_number);
            }

            if (blank($employee->name)) {
                $fullName = trim(implode(' ', array_filter([
                    $employee->first_name,
                    $employee->last_name,
                ])));

                $employee->name = $fullName !== ''
                    ? $fullName
                    : 'Employee ' . $employee->employee_number;
            }

            if (blank($employee->start_date) && filled($employee->badge_issued_on)) {
                $employee->start_date = $employee->badge_issued_on;
            }
        });
    }

    private function normalizeOptionalTextFields(): void
    {
        foreach ([
            'badge_number',
            'badge_printed_number',
            'first_name',
            'last_name',
            'email',
            'badge_company_name',
            'nationality',
            'role',
            'attendance_app_role',
            'attendance_app_scope',
        ] as $field) {
            if (! is_string($this->{$field})) {
                continue;
            }

            $value = trim($this->{$field});
            $this->{$field} = $value === '' ? null : $value;
        }

        if (filled($this->email)) {
            $this->email = Str::lower((string) $this->email);
        }
    }

    private static function makeEmployeeNumber(): string
    {
        do {
            $employeeNumber = 'EMP-' . now()->format('ymd') . '-' . Str::upper(Str::random(5));
        } while (self::query()->where('employee_number', $employeeNumber)->exists());

        return $employeeNumber;
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

    /**
     * 직원에 연결된 로그인 계정(있으면). 직접 등록 시 관리자/작업자 권한이 여기에 부여된다.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function badgeQrTokens(): HasMany
    {
        return $this->hasMany(EmployeeBadgeQrToken::class);
    }

    public function dailyWorkAssignments(): HasMany
    {
        return $this->hasMany(DailyWorkAssignment::class);
    }
}
