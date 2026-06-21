<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        'preferred_name',
        'email',
        'phone',
        'nationality',
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
            'visa_expires_on' => 'date',
            'safety_training_expires_on' => 'date',
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
            $registration->refreshAutomationSignals();
        });

        // 단일 연동 트리거: 회원등록이 approved/active 로 저장되면 Create/Edit/Approve
        // 어떤 경로든 직원·계정·문서로 자동 반영한다. syncEmployee 의 자기 저장은
        // saveQuietly 라 이 saved 훅을 재귀 호출하지 않는다.
        static::saved(function (MemberRegistration $registration): void {
            if (in_array($registration->onboarding_status, ['approved', 'active'], true)) {
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

    public function approve(?User $user = null): Employee
    {
        // save() 가 saved 훅을 통해 syncDownstream 을 실행한다.
        $this->forceFill([
            'onboarding_status' => 'active',
            'approved_at' => now(),
            'approved_by_id' => $user?->id,
        ])->save();

        return $this->employee()->firstOrFail();
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
            ['document_type' => 'id', 'title' => '신분증 (ID)', 'expires_on' => null],
            ['document_type' => 'safety_training', 'title' => '안전교육 수료증 (Safety Training)', 'expires_on' => $this->safety_training_expires_on],
        ];

        if ($this->visa_type || $this->visa_expires_on) {
            $checklist[] = ['document_type' => 'visa', 'title' => '비자 (Visa)', 'expires_on' => $this->visa_expires_on];
        }

        foreach ($checklist as $doc) {
            MemberDocument::query()->firstOrCreate(
                [
                    'member_registration_id' => $this->id,
                    'document_type' => $doc['document_type'],
                ],
                [
                    'title' => $doc['title'],
                    'status' => $status,
                    'expires_on' => $doc['expires_on'],
                    'verified_at' => $status === 'verified' ? now() : null,
                ],
            );
        }
    }

    public function syncEmployee(): Employee
    {
        $employeeNumber = $this->employee_number ?: $this->registration_number;
        $email = $this->email ? Str::lower($this->email) : null;

        $employee = $this->employee()->first()
            ?? Employee::query()->where('employee_number', $employeeNumber)->first()
            ?? ($email ? Employee::query()->where('email', $email)->first() : null)
            ?? new Employee(['employee_number' => $employeeNumber]);

        $employee->fill([
            'employee_number' => $employeeNumber,
            'company_id' => $this->company_id,
            'site_id' => $this->site_id,
            'team_id' => $this->team_id,
            'badge_number' => $this->badge_number,
            'name' => $this->full_name,
            'email' => $email,
            'nationality' => $this->nationality,
            'role' => $this->role ?: $this->trade,
            'employment_status' => 'active',
            'visa_expires_on' => $this->visa_expires_on,
            'safety_training_expires_on' => $this->safety_training_expires_on,
            'payload' => array_merge($employee->payload ?? [], [
                'member_registration_id' => $this->id,
                'member_type' => $this->member_type,
                'site_id' => $this->site_id,
                'trade' => $this->trade,
                'source' => 'smart-member-registration',
            ]),
        ]);

        $employee->save();

        $this->forceFill(['employee_id' => $employee->id])->saveQuietly();

        return $employee;
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
        $score += $this->identity_status === 'verified' ? 15 : 0;
        $score += $this->document_status === 'verified' ? 15 : 0;
        $score += $this->safety_training_expires_on ? 5 : 0;

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
}
