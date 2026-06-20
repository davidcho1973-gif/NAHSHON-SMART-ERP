<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
        $this->forceFill([
            'onboarding_status' => 'active',
            'approved_at' => now(),
            'approved_by_id' => $user?->id,
        ])->save();

        $employee = $this->syncEmployee();
        $this->syncAccessUser($employee);

        return $employee;
    }

    public function syncEmployee(): Employee
    {
        $employee = Employee::query()->updateOrCreate(
            ['employee_number' => $this->employee_number ?: $this->registration_number],
            [
                'company_id' => $this->company_id,
                'site_id' => $this->site_id,
                'team_id' => $this->team_id,
                'badge_number' => $this->badge_number,
                'name' => $this->full_name,
                'email' => $this->email ? Str::lower($this->email) : null,
                'nationality' => $this->nationality,
                'role' => $this->role ?: $this->trade,
                'employment_status' => 'active',
                'visa_expires_on' => $this->visa_expires_on,
                'safety_training_expires_on' => $this->safety_training_expires_on,
                'payload' => [
                    'member_registration_id' => $this->id,
                    'member_type' => $this->member_type,
                    'site_id' => $this->site_id,
                    'trade' => $this->trade,
                    'source' => 'smart-member-registration',
                ],
            ],
        );

        $this->forceFill(['employee_id' => $employee->id])->saveQuietly();

        return $employee;
    }

    private function syncAccessUser(Employee $employee): ?User
    {
        if (! $this->email) {
            return null;
        }

        $accessUser = User::query()->firstOrNew(['email' => Str::lower($this->email)]);

        $accessUser->fill([
            'employee_id' => $employee->id,
            'name' => $this->full_name,
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
