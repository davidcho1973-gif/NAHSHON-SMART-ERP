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

    /** 직접고용 — 우리 회사가 시급 지급. 시간 관리가 핵심(퇴근 자동마감 금지). */
    public const TYPE_DIRECT = 'direct';

    /** 간접고용 — 하청업체 직원. 출역 인원이 핵심(퇴근 누락 시 16:00 자동마감). */
    public const TYPE_INDIRECT = 'indirect';

    /** 관리직(현장/시스템 관리자). */
    public const TYPE_STAFF = 'staff';

    /** 원청 담당자 — 출퇴근 대상 아님. */
    public const TYPE_CLIENT = 'client';

    public const EMPLOYMENT_TYPES = [
        self::TYPE_DIRECT => '직접고용 (시급)',
        self::TYPE_INDIRECT => '간접고용 (협력사)',
        self::TYPE_STAFF => '관리직',
        self::TYPE_CLIENT => '원청',
    ];

    /** 출퇴근 정책 — 정밀 시간관리(시급/일급 직영). 퇴근 시각이 임금에 직결된다. */
    public const POLICY_HOURLY = 'hourly';

    /** 출퇴근 정책 — 출석 확인만(관리직·월급제). 급여는 정액이라 퇴근 시각은 참고용. */
    public const POLICY_PRESENCE = 'presence';

    /** 출퇴근 정책 — 출역 인원체크만(협력사). 임금은 소속사가 지급한다. */
    public const POLICY_HEADCOUNT = 'headcount';

    /** 출퇴근 정책 — 대상 아님(원청). */
    public const POLICY_NONE = 'none';

    public function employmentTypeLabel(): string
    {
        return self::EMPLOYMENT_TYPES[$this->employment_type] ?? (string) $this->employment_type;
    }

    /**
     * 이 직원의 출퇴근 관리 정책.
     *
     * 협력사(간접고용)=인원체크만, 관리직 또는 월급제(pay_type=salary)=출석만,
     * 그 외 직영=정밀 시간관리. 급여 프로필이 없는 직영은 시급으로 본다(기본값과 일치).
     */
    public function attendancePolicy(): string
    {
        return match (true) {
            $this->employment_type === self::TYPE_CLIENT => self::POLICY_NONE,
            $this->employment_type === self::TYPE_INDIRECT => self::POLICY_HEADCOUNT,
            $this->employment_type === self::TYPE_STAFF => self::POLICY_PRESENCE,
            $this->payrollProfile?->pay_type === 'salary' => self::POLICY_PRESENCE,
            default => self::POLICY_HOURLY,
        };
    }

    /** 시급 계산 대상인가(퇴근 시각이 임금에 직결되는가). */
    public function isHourly(): bool
    {
        return $this->attendancePolicy() === self::POLICY_HOURLY;
    }

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
        'preferred_language',
        'role',
        'start_date',
        'employment_status',
        'employment_type',
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
                    : 'Employee '.$employee->employee_number;
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
            $employeeNumber = 'EMP-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
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

    /** 급여 프로필 — pay_type(시급/연봉/일급)이 출퇴근 정책 판별에도 쓰인다. */
    public function payrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class);
    }

    /** 제출된 W-9 — 1099 지급의 전제조건. 없으면 24% backup withholding 대상. */
    public function w9Form(): HasOne
    {
        return $this->hasOne(W9Form::class);
    }

    public function communicationRoomMemberships(): HasMany
    {
        return $this->hasMany(CommunicationRoomMember::class);
    }

    public function communicationMessages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'sender_employee_id');
    }

    public function badgeQrTokens(): HasMany
    {
        return $this->hasMany(EmployeeBadgeQrToken::class);
    }

    /** 기억해 둔 작업자 휴대폰들 — 게이트 QR 을 스캔하면 본인으로 바로 인식된다. */
    public function devices(): HasMany
    {
        return $this->hasMany(WorkerDevice::class);
    }

    public function dailyWorkAssignments(): HasMany
    {
        return $this->hasMany(DailyWorkAssignment::class);
    }
}
