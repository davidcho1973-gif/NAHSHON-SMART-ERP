<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'company_id',
        'site_id',
        'team_id',
        'registration_number',
        'employee_number',
        'badge_number',
        'member_type',
        'full_name',
        'first_name',
        'last_name',
        'preferred_name',
        'email',
        'phone',
        'nationality',
        'preferred_language',
        'date_of_birth',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'role',
        'trade',
        'start_date',
        'end_date',
        'visa_type',
        'visa_expires_on',
        'safety_training_expires_on',
        'identity_status',
        'document_status',
        'onboarding_status',
        'automation_score',
        'risk_level',
        'interview_status',
        'interviewed_at',
        'interviewed_by_id',
        'interview_notes',
        'safety_training_status',
        'safety_training_completed_on',
        'badge_registration_status',
        'nfc_raw_uid',
        'badge_photo_path',
        'badge_company_name',
        'badge_first_name',
        'badge_last_name',
        'badge_role',
        'badge_issued_on',
        'badge_analysis_model',
        'badge_analyzed_at',
        'badge_analysis_payload',
        'privacy_consent_at',
        'privacy_consent_language',
        'invite_token',
        'invited_at',
        'submitted_at',
        'approved_at',
        'approved_by_id',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'date_of_birth' => 'date',
            'visa_expires_on' => 'date',
            'safety_training_expires_on' => 'date',
            'interviewed_at' => 'datetime',
            'safety_training_completed_on' => 'date',
            'badge_issued_on' => 'date',
            'badge_analyzed_at' => 'datetime',
            'badge_analysis_payload' => 'array',
            'privacy_consent_at' => 'datetime',
            'invited_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberRegistration $registration): void {
            $registration->registration_number ??= self::makeRegistrationNumber();
            $registration->employee_number ??= $registration->registration_number;
            $registration->invite_token ??= (string) Str::uuid();
            $registration->invited_at ??= now();
        });

        static::saving(function (MemberRegistration $registration): void {
            if (filled($registration->nfc_raw_uid) && (blank($registration->badge_number) || $registration->isDirty('nfc_raw_uid'))) {
                $registration->badge_number = self::normalizeNfcUid($registration->nfc_raw_uid);
            }

            $registration->refreshAutomationSignals();
        });

        // 단일 연동 트리거: 최종 Active 상태가 될 때만 직원·계정·문서로 반영한다.
        // 지원서/인터뷰/안전교육 단계에서는 Employee 레코드를 만들지 않는다.
        static::saved(function (MemberRegistration $registration): void {
            if ($registration->onboarding_status === 'active') {
                $registration->syncDownstream();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function intakeUrl(): string
    {
        return route('member-registration.show', $this->invite_token);
    }

    /**
     * @return array<string, string>
     */
    public static function languageOptions(): array
    {
        return [
            'es' => 'Español',
            'en' => 'English',
            'ko' => '한국어',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            'Electrician' => 'Electrician',
            'Plumber' => 'Plumber',
            'Welder' => 'Welder',
            'HVAC' => 'HVAC',
            'General Labor' => 'General Labor',
            'Foreman' => 'Foreman',
            'Engineer' => 'Engineer',
            'Safety' => 'Safety',
            'Spotter' => 'Spotter',
            'Other' => 'Other',
        ];
    }

    public static function normalizeNfcUid(?string $uid): ?string
    {
        if (! is_string($uid)) {
            return null;
        }

        $clean = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $uid) ?? '');

        if ($clean === '') {
            return null;
        }

        return 'N-' . Str::substr($clean, -9);
    }

    public function markInterviewPassed(?User $user = null): void
    {
        $this->forceFill([
            'interview_status' => 'passed',
            'interviewed_at' => $this->interviewed_at ?: now(),
            'interviewed_by_id' => $this->interviewed_by_id ?: $user?->id,
            'onboarding_status' => $this->onboarding_status === 'active' ? 'active' : 'safety_training',
        ])->save();
    }

    public function markSafetyTrainingCompleted(): void
    {
        $this->forceFill([
            'safety_training_status' => 'completed',
            'safety_training_completed_on' => $this->safety_training_completed_on ?: now()->toDateString(),
            'document_status' => $this->document_status === 'missing' ? 'pending' : $this->document_status,
            'onboarding_status' => $this->onboarding_status === 'active' ? 'active' : 'badge_pending',
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function activationBlockers(): array
    {
        $blockers = [];

        if ($this->safety_training_status !== 'completed') {
            $blockers[] = 'Hoffman safety training must be completed first.';
        }

        if (blank($this->badge_number)) {
            $blockers[] = 'NFC ID is required. Scan the badge UID first.';
        }

        if (blank($this->badge_photo_path)) {
            $blockers[] = 'Badge photo is required for later verification.';
        }

        return $blockers;
    }

    public function canActivateEmployee(): bool
    {
        return $this->activationBlockers() === [];
    }

    public function activateAsEmployee(?User $user = null): Employee
    {
        $blockers = $this->activationBlockers();

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'onboarding_status' => implode(' ', $blockers),
            ]);
        }

        $this->forceFill([
            'badge_registration_status' => 'registered',
            'start_date' => $this->start_date ?: $this->badge_issued_on,
            'onboarding_status' => 'active',
            'approved_at' => now(),
            'approved_by_id' => $user?->id,
        ])->save();

        return $this->employee()->firstOrFail();
    }

    public function approve(?User $user = null): Employee
    {
        return $this->activateAsEmployee($user);
    }

    /**
     * 회원등록을 직원(Employees) · 접근계정(Access Control) · 문서(Member Documents)로
     * 일괄 반영한다. updateOrCreate/firstOrCreate 기반이라 여러 번 호출해도 안전(idempotent).
     */
    public function syncDownstream(): Employee
    {
        return DB::transaction(function (): Employee {
            $employee = $this->syncEmployee();
            $this->syncAccessUser($employee);
            $this->syncDocuments();

            return $employee;
        });
    }

    /**
     * 접근계정이 생성/연결됐는지 여부(이메일이 없으면 계정은 생성되지 않는다).
     */
    public function hasAccessAccount(): bool
    {
        return User::query()
            ->when(
                $this->employee_id,
                fn ($query) => $query->where('employee_id', $this->employee_id),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->when($this->email, fn ($query) => $query->orWhere('email', Str::lower($this->email)))
            ->exists();
    }

    /**
     * 기본 서류 체크리스트를 생성한다. 이미 존재하는 문서는 건드리지 않는다(firstOrCreate).
     */
    private function syncDocuments(): void
    {
        $status = match ($this->document_status) {
            'verified' => 'verified',
            'expired' => 'expired',
            default => 'pending',
        };

        $checklist = [
            ['document_type' => 'id', 'title' => '신분증 (ID)', 'expires_on' => null, 'status' => $status],
            ['document_type' => 'safety_training', 'title' => '안전교육 수료증 (Safety Training)', 'expires_on' => $this->safety_training_expires_on, 'status' => $status],
        ];

        if ($this->visa_type || $this->visa_expires_on) {
            $checklist[] = ['document_type' => 'visa', 'title' => '비자 (Visa)', 'expires_on' => $this->visa_expires_on, 'status' => $status];
        }

        if ($this->badge_photo_path || $this->badge_number) {
            $checklist[] = [
                'document_type' => 'nfc',
                'title' => 'Hoffman Badge / NFC',
                'status' => $this->badge_registration_status === 'registered' ? 'verified' : 'pending',
                'issued_on' => $this->badge_issued_on,
                'file_path' => $this->publicStorageUrl($this->badge_photo_path),
                'extracted_data' => array_filter([
                    'nfc_raw_uid' => $this->nfc_raw_uid,
                    'nfc_id' => $this->badge_number,
                    'company_name' => $this->badge_company_name,
                    'last_name' => $this->badge_last_name,
                    'first_name' => $this->badge_first_name,
                    'role' => $this->badge_role,
                    'issued_on' => optional($this->badge_issued_on)->toDateString(),
                    'analysis_model' => $this->badge_analysis_model,
                ]),
            ];
        }

        foreach ($checklist as $doc) {
            $document = MemberDocument::query()->firstOrCreate(
                [
                    'member_registration_id' => $this->id,
                    'document_type' => $doc['document_type'],
                ],
                [
                    'title' => $doc['title'],
                    'status' => $doc['status'],
                    'issued_on' => $doc['issued_on'] ?? null,
                    'expires_on' => $doc['expires_on'] ?? null,
                    'file_path' => $doc['file_path'] ?? null,
                    'extracted_data' => $doc['extracted_data'] ?? null,
                    'verified_at' => $doc['status'] === 'verified' ? now() : null,
                ],
            );

            $updates = array_filter([
                'issued_on' => $doc['issued_on'] ?? null,
                'expires_on' => $doc['expires_on'] ?? null,
                'file_path' => $doc['file_path'] ?? null,
                'extracted_data' => $doc['extracted_data'] ?? null,
            ], fn (mixed $value): bool => filled($value));

            if ($updates !== []) {
                $document->fill($updates)->save();
            }
        }
    }

    public function syncEmployee(): Employee
    {
        $email = $this->email ? Str::lower($this->email) : null;
        $linkedEmployee = $this->employee()->first();

        if ($linkedEmployee && ! $this->employeeMatchesRegistration($linkedEmployee)) {
            $linkedEmployee = null;
            $this->forceFill(['employee_id' => null])->saveQuietly();
        }

        $employeeNumber = $this->resolveEmployeeNumber($linkedEmployee);
        $firstName = $this->badge_first_name ?: $this->first_name;
        $lastName = $this->badge_last_name ?: $this->last_name;
        $fullName = $this->full_name ?: trim(implode(' ', array_filter([$firstName, $lastName])));
        $role = $this->badge_role ?: $this->role ?: $this->trade;
        $startDate = $this->start_date ?: $this->badge_issued_on;

        $employee = $linkedEmployee
            ?? ($email ? Employee::query()->where('email', $email)->first() : null)
            ?? $this->matchingEmployeeByNumber($employeeNumber)
            ?? new Employee(['employee_number' => $employeeNumber]);

        $employee->fill([
            'employee_number' => $employeeNumber,
            'company_id' => $this->company_id,
            'site_id' => $this->site_id,
            'team_id' => $this->team_id,
            'badge_number' => $this->badge_number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $fullName,
            'email' => $email,
            'badge_company_name' => $this->badge_company_name,
            'badge_issued_on' => $this->badge_issued_on,
            'badge_photo_path' => $this->badge_photo_path,
            'badge_analysis_model' => $this->badge_analysis_model,
            'badge_analyzed_at' => $this->badge_analyzed_at,
            'badge_analysis_payload' => $this->badge_analysis_payload,
            'nationality' => $this->nationality,
            'role' => $role,
            'start_date' => $startDate,
            'employment_status' => 'active',
            'visa_expires_on' => $this->visa_expires_on,
            'safety_training_expires_on' => $this->safety_training_expires_on,
            'payload' => array_merge($employee->payload ?? [], [
                'member_registration_id' => $this->id,
                'member_type' => $this->member_type,
                'site_id' => $this->site_id,
                'trade' => $this->trade,
                'application_language' => $this->preferred_language,
                'nfc_raw_uid' => $this->nfc_raw_uid,
                'source' => 'smart-member-registration',
            ]),
        ]);

        $employee->save();

        $this->forceFill(['employee_id' => $employee->id])->saveQuietly();

        return $employee;
    }

    private function resolveEmployeeNumber(?Employee $currentEmployee = null): string
    {
        $preferred = $this->employee_number ?: $this->registration_number;
        $employeeNumber = $this->availableEmployeeNumber($preferred, $currentEmployee);

        if ($employeeNumber !== $preferred) {
            $this->forceFill(['employee_number' => $employeeNumber])->saveQuietly();
        }

        return $employeeNumber;
    }

    private function availableEmployeeNumber(string $candidate, ?Employee $currentEmployee = null): string
    {
        $conflict = Employee::query()
            ->where('employee_number', $candidate)
            ->when($currentEmployee?->exists, fn ($query) => $query->whereKeyNot($currentEmployee->getKey()))
            ->first();

        if (! $conflict || $this->employeeMatchesRegistration($conflict)) {
            return $candidate;
        }

        $fallback = $this->registration_number;
        $fallbackConflict = Employee::query()
            ->where('employee_number', $fallback)
            ->when($currentEmployee?->exists, fn ($query) => $query->whereKeyNot($currentEmployee->getKey()))
            ->first();

        if (! $fallbackConflict || $this->employeeMatchesRegistration($fallbackConflict)) {
            return $fallback;
        }

        return Str::limit($fallback, 70, '') . '-' . $this->id;
    }

    private function matchingEmployeeByNumber(string $employeeNumber): ?Employee
    {
        $employee = Employee::query()->where('employee_number', $employeeNumber)->first();

        if (! $employee || ! $this->employeeMatchesRegistration($employee)) {
            return null;
        }

        return $employee;
    }

    private function employeeMatchesRegistration(Employee $employee): bool
    {
        $payloadRegistrationId = (int) data_get($employee->payload, 'member_registration_id');

        if ($payloadRegistrationId === $this->id) {
            return true;
        }

        if (
            $this->email &&
            $employee->email &&
            Str::lower($employee->email) === Str::lower($this->email)
        ) {
            return true;
        }

        $registrationNumbers = array_filter([
            $this->employee_number,
            $this->registration_number,
        ]);

        if (
            in_array($employee->employee_number, $registrationNumbers, true) &&
            Str::lower($employee->name) === Str::lower($this->full_name)
        ) {
            return true;
        }

        return false;
    }

    private function syncAccessUser(Employee $employee): ?User
    {
        if (! $this->email) {
            return null;
        }

        $email = Str::lower($this->email);

        $accessUser = User::query()->where('employee_id', $employee->id)->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User();

        $accessUser->fill([
            'employee_id' => $employee->id,
            'name' => $this->full_name,
            'email' => $email,
            'access_role' => $accessUser->access_role ?: 'worker',
            'access_scope' => $accessUser->access_scope ?: 'self',
            'account_status' => $accessUser->account_status ?: 'active',
            'allowed_company_id' => $accessUser->allowed_company_id ?: $this->company_id,
            'allowed_site_id' => $accessUser->allowed_site_id ?: $this->site_id,
            'allowed_team_id' => $accessUser->allowed_team_id ?: $this->team_id,
            'email_verified_at' => $accessUser->email_verified_at ?: now(),
        ]);

        if (! $accessUser->exists || ! $accessUser->password) {
            $accessUser->password = Str::password(32);
        }

        $accessUser->save();

        return $accessUser;
    }

    private static function makeRegistrationNumber(): string
    {
        return 'MR-' . now()->format('ymd') . '-' . Str::upper(Str::random(6));
    }

    private function refreshAutomationSignals(): void
    {
        $score = 25;
        $score += $this->email ? 10 : 0;
        $score += $this->phone ? 10 : 0;
        $score += $this->company_id ? 10 : 0;
        $score += $this->site_id ? 10 : 0;
        $score += $this->role || $this->trade ? 10 : 0;
        $score += ($this->first_name && $this->last_name) || $this->full_name ? 10 : 0;
        $score += $this->identity_status === 'verified' ? 15 : 0;
        $score += $this->document_status === 'verified' ? 15 : 0;
        $score += $this->safety_training_expires_on ? 5 : 0;
        $score += $this->safety_training_status === 'completed' ? 10 : 0;
        $score += $this->badge_number ? 10 : 0;

        $this->automation_score = min(100, $score);
        $this->risk_level = $this->calculateRiskLevel();
    }

    private function calculateRiskLevel(): string
    {
        $visa = $this->visa_expires_on ? Carbon::parse($this->visa_expires_on) : null;
        $safety = $this->safety_training_expires_on ? Carbon::parse($this->safety_training_expires_on) : null;

        if (
            $this->identity_status === 'needs_review' ||
            $this->document_status === 'expired' ||
            ($visa && $visa->lte(now()->addDays(30))) ||
            ($safety && $safety->lte(now()->addDays(30)))
        ) {
            return 'high';
        }

        if (
            $this->document_status !== 'verified' ||
            $this->automation_score < 75 ||
            ($visa && $visa->lte(now()->addDays(60))) ||
            ($safety && $safety->lte(now()->addDays(60)))
        ) {
            return 'medium';
        }

        return 'low';
    }

    private function publicStorageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '/storage/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
