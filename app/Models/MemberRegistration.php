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
        'applicant_code',
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
        'position',
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
        'badge_printed_number',
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

        // Employee/account/document sync only happens when the applicant transitions to active.
        // Manual re-sync remains available from the 입사지원 관리 화면 (api_resyncApplicant).
        static::saved(function (MemberRegistration $registration): void {
            if ($registration->onboarding_status === 'active'
                && ($registration->wasRecentlyCreated || $registration->wasChanged('onboarding_status'))) {
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

    public function qrUrl(): string
    {
        return route('member-registration.qr', $this->invite_token);
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

    /**
     * @return array<string, string>
     */
    public static function availableLanguageOptions(): array
    {
        return [
            'Korean' => 'Korean',
            'English' => 'English',
            'Spanish' => 'Spanish',
            'Other' => 'Other',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function onboardingStatusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'invited' => 'Invited',
            'submitted' => 'Submitted',
            'under_review' => 'Under review',
            'interview_passed' => 'Interview passed',
            'safety_completed' => 'Safety training completed',
            'badge_pending' => 'Badge / NFC pending',
            'active' => 'Active',
            'rejected' => 'Rejected',
            'archived' => 'Archived',
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

        return 'N-'.Str::substr($clean, -9);
    }

    public function ensureApplicantCode(): string
    {
        if (filled($this->applicant_code)) {
            return $this->applicant_code;
        }

        $this->forceFill(['applicant_code' => self::makeApplicantCode()])->saveQuietly();

        return $this->applicant_code;
    }

    public function markInterviewPassed(?User $user = null): void
    {
        $this->forceFill([
            'interview_status' => 'passed',
            'interviewed_at' => $this->interviewed_at ?: now(),
            'interviewed_by_id' => $this->interviewed_by_id ?: $user?->id,
            'onboarding_status' => in_array($this->onboarding_status, [
                'active',
                'safety_completed',
                'badge_pending',
            ], true) ? $this->onboarding_status : 'interview_passed',
        ])->save();
    }

    public function markSafetyTrainingCompleted(): void
    {
        $this->forceFill([
            'safety_training_status' => 'completed',
            'safety_training_completed_on' => $this->safety_training_completed_on ?: now()->toDateString(),
            'document_status' => blank($this->document_status) || $this->document_status === 'missing'
                ? 'pending'
                : $this->document_status,
            'onboarding_status' => $this->onboarding_status === 'active' ? 'active' : 'safety_completed',
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function activationBlockers(): array
    {
        $blockers = [];

        if (! $this->submitted_at) {
            $blockers[] = 'Application must be submitted first.';
        }

        if (! $this->privacy_consent_at) {
            $blockers[] = 'Privacy consent is required.';
        }

        // 하청업체 및 외부 인원(vendor, visitor, driver 등)의 경우
        // 제출 및 개인정보동의 조건만 검사하고 나머지 정직원용 블로커는 생략합니다.
        if (in_array($this->member_type, ['vendor', 'visitor', 'driver'], true)) {
            return $blockers;
        }

        if (! $this->documents()->where('document_type', 'id')->exists()) {
            $blockers[] = 'Government ID document is required.';
        }

        if ($this->interview_status !== 'passed') {
            $blockers[] = 'Interview must be passed first.';
        }

        if ($this->safety_training_status !== 'completed') {
            $blockers[] = 'Hoffman safety training must be completed first.';
        }

        if (blank($this->nfc_raw_uid)) {
            $blockers[] = 'Raw NFC UID is required. Scan the badge first.';
        }

        if (blank($this->badge_number) || $this->badge_number !== self::normalizeNfcUid($this->nfc_raw_uid)) {
            $blockers[] = 'NFC ID is required. Scan the badge UID first.';
        }

        if (blank($this->badge_photo_path)) {
            $blockers[] = 'Badge photo is required for later verification.';
        }

        if (blank($this->badge_issued_on)) {
            $blockers[] = 'Badge issued date is required because it becomes the hire date.';
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
            'badge_registration_status' => $this->badge_number ? 'registered' : 'pending',
            'start_date' => $this->badge_issued_on ?: $this->start_date ?: now()->toDateString(),
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

    public function rejectApplication(?User $user = null): void
    {
        $this->forceFill([
            'onboarding_status' => 'rejected',
            'approved_by_id' => $this->approved_by_id ?: $user?->id,
        ])->save();
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
                    'printed_number' => $this->badge_printed_number,
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
        return $this->syncEmployeeRecord('active', 'smart-member-registration');
    }

    private function syncEmployeeRecord(string $employmentStatus, string $source): Employee
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
            ?? ($email ? $this->matchingEmployeeByEmail($email) : null)
            ?? $this->matchingEmployeeByNumber($employeeNumber)
            ?? new Employee(['employee_number' => $employeeNumber]);

        // 이메일이 이미 다른 직원의 것이면 이 레코드에는 적지 않는다 — employees.email
        // 은 유니크라 저장이 터지고, 유니크가 아니었더라도 두 직원이 같은 이메일을
        // 갖는 순간 다음 조회부터 아무나 걸린다.
        if ($email && Employee::query()
            ->where('email', $email)
            ->when($employee->exists, fn ($q) => $q->whereKeyNot($employee->getKey()))
            ->exists()) {
            $email = $employee->exists ? $employee->email : null;
        }

        $employee->fill([
            'employee_number' => $employeeNumber,
            'company_id' => $this->company_id,
            'site_id' => $this->site_id,
            'team_id' => $this->team_id,
            'badge_number' => $this->badge_number,
            'badge_printed_number' => $this->badge_printed_number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $fullName,
            'email' => $email,
            // 등록 폼이 이미 받은 번호다. 여기서 안 옮기면 나중에 손으로 다시 묻게 된다.
            'phone' => $this->phone ?: null,
            'badge_company_name' => $this->badge_company_name,
            'badge_issued_on' => $this->badge_issued_on,
            'badge_photo_path' => $this->badge_photo_path,
            'badge_analysis_model' => $this->badge_analysis_model,
            'badge_analyzed_at' => $this->badge_analyzed_at,
            'badge_analysis_payload' => $this->badge_analysis_payload,
            'nationality' => $this->nationality,
            'role' => $role,
            // 직책은 등록서류에서 그대로 따라온다. 비어 있으면 직원 쪽 값을 지운다는
            // 뜻이 아니라 "모른다"이므로, 이미 있는 값을 덮지 않는다.
            'position' => $this->position ?: $employee->position,
            'start_date' => $startDate,
            'employment_status' => $employee->exists && $employee->employment_status === 'active'
                ? 'active'
                : $employmentStatus,
            'visa_expires_on' => $this->visa_expires_on,
            'safety_training_expires_on' => $this->safety_training_expires_on,
            'payload' => array_merge($employee->payload ?? [], [
                'member_registration_id' => $this->id,
                'member_type' => $this->member_type,
                'site_id' => $this->site_id,
                'trade' => $this->trade,
                'application_language' => $this->preferred_language,
                'nfc_raw_uid' => $this->nfc_raw_uid,
                'applicant_code' => $this->applicant_code,
                'source' => $source,
            ]),
        ]);

        // 고용형태·언어 — 값이 있을 때만 채운다(컬럼 NOT NULL 기본값을 살리기 위해
        // null 을 명시적으로 넣지 않는다). 이 보완이 없으면 정식(B) 경로로 들어온
        // 협력사 인원이 기본값 'direct'(시급 직영)로 둔갑해 급여 대상에 올랐다.
        // 회사 분류가 정본이고, 이미 있는 값은 보존한다. 미분류 회사는 그대로 둔다
        // (QR 경로는 작업자에게 직접 묻고, 관리자 분류 시 따라잡는다).
        if (! $employee->employment_type && ($companyType = $this->company?->employmentType())) {
            $employee->employment_type = $companyType;
        }
        if ($this->preferred_language && $employee->preferred_language !== $this->preferred_language
            && (! $employee->exists || ! $employee->preferred_language)) {
            $employee->preferred_language = $this->preferred_language;
        }

        $employee->save();

        $this->forceFill(['employee_id' => $employee->id])->saveQuietly();

        return $employee;
    }

    /**
     * 이메일로 기존 직원을 찾되, 이름이 같을 때만 같은 사람으로 본다.
     *
     * 공개 QR 폼의 이메일은 검증되지 않은 자유 입력이다. 이메일만으로 매칭하면
     * 남의 이메일을 적는 순간 그 직원의 이름·소속이 등록 폼 값으로 덮여 신원이
     * 통째로 바뀐다(시험에서 실제로 재현됐다). 같은 사람이 다시 등록하는 경우
     * (지원하는 흐름)는 이름이 같으므로 그대로 이어진다.
     */
    private function matchingEmployeeByEmail(string $email): ?Employee
    {
        $found = Employee::query()->where('email', $email)->first();

        if (! $found) {
            return null;
        }

        $same = mb_strtolower(trim((string) $found->name)) === mb_strtolower(trim((string) $this->full_name));

        if (! $same) {
            report(new \RuntimeException(
                "등록 이메일 {$email} 이 이미 다른 이름의 직원에게 있어 매칭하지 않습니다 (registration {$this->id})."
            ));

            return null;
        }

        return $found;
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

        return Str::limit($fallback, 70, '').'-'.$this->id;
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

    /** 이 등록이 로그인 없이 열리는 공개 QR 폼에서 왔는가. */
    private function fromPublicQuickForm(): bool
    {
        return data_get($this->payload, 'invite.source') === 'worker-quick-qr';
    }

    private function syncAccessUser(Employee $employee): ?User
    {
        if (! $this->email) {
            return null;
        }

        // 공개 QR 간편등록은 <b>인력 등록</b>이지 계정 발급이 아니다.
        //
        // 이 폼은 현장 벽에 붙은 QR 로 누구나 열 수 있고 이메일은 검증되지 않은 자유
        // 입력이다. 그런데 로그인은 "그 이메일의 활성 계정이 있는가"만 보므로, 여기서
        // 계정을 만들면 인터넷의 누구든 자기 구글 주소를 적어 ERP 로그인 계정을 스스로
        // 발급받게 된다. 그래서 계정은 관리자가 승인할 때만 생긴다 — 정식 입사지원서
        // 경로가 이미 그렇게 동작한다(승인 전에는 계정이 없다).
        //
        // 등록·Employee·서류·출퇴근은 그대로다. 현장 출퇴근은 게이트 QR(로그인 불필요)로
        // 굴러가므로 이 차단이 현장 업무를 멈추지 않는다.
        if ($this->fromPublicQuickForm() && $this->approved_by_id === null) {
            return null;
        }

        $email = Str::lower($this->email);

        $accessUser = User::query()->where('employee_id', $employee->id)->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User;

        // 공개 폼에서 온 등록은 <b>기존 계정을 절대 흡수하지 않는다.</b> 아래 일반 규칙은
        // "이미 다른 직원에 묶인 계정"만 막는데, 시드·초기 구축으로 만든 최고관리자는
        // employee_id 가 비어 있어 그 그물을 빠져나간다 — 사장 이메일을 적으면 관리자
        // 계정의 이름이 폼 입력값으로 바뀌고 가짜 직원에 묶인다. 그 문을 여기서 닫는다.
        if ($this->fromPublicQuickForm() && $accessUser->exists) {
            report(new \RuntimeException(
                "공개 등록 이메일 {$email} 이 기존 계정과 같아 계정 연결을 건너뜁니다 (registration {$this->id})."
            ));

            return null;
        }

        // 공개 QR 폼의 이메일은 검증되지 않은 자유 입력이다. 그 이메일이 이미 다른
        // 사람(다른 직원, 혹은 관리자 계정)에 붙어 있으면 그 계정을 이쪽으로 갈아타지
        // 않는다 — 누군가 사장 이메일을 적는 순간 관리자 계정의 이름과 직원 연결이
        // 조용히 바뀌는 문을 닫는다. 이 경우 계정 없이 등록만 진행한다(관리자가 확인).
        if ($accessUser->exists
            && $accessUser->employee_id !== null
            && (int) $accessUser->employee_id !== (int) $employee->id) {
            report(new \RuntimeException(
                "등록 이메일 {$email} 이 이미 다른 직원의 계정에 연결되어 있어 계정 연결을 건너뜁니다 (registration {$this->id})."
            ));

            return null;
        }

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
        return 'MR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }

    private static function makeApplicantCode(): string
    {
        do {
            $code = 'AP-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (self::query()->where('applicant_code', $code)->exists());

        return $code;
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
