<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    /** 자사 — 소속 작업자는 직접고용(우리가 시급을 지급). */
    public const TYPE_OWN = 'own';

    /** 협력사(하청) — 소속 작업자는 간접고용(출역 인원 관리). */
    public const TYPE_PARTNER = 'partner';

    /** 원청·발주처 — 출퇴근 대상이 아니다. */
    public const TYPE_CLIENT = 'client';

    /** 아직 분류하지 않음 — 등록 폼이 작업자에게 한 번 물어본다. */
    public const TYPE_UNKNOWN = 'unknown';

    public const COMPANY_TYPES = [
        self::TYPE_OWN => '자사 (직접고용)',
        self::TYPE_PARTNER => '협력사 (간접고용)',
        self::TYPE_CLIENT => '원청 · 발주처',
        self::TYPE_UNKNOWN => '미지정',
    ];

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'status',
        'company_type',
        'payload',
    ];

    /**
     * 이 회사 소속으로 등록되는 작업자의 고용 형태.
     * 분류되지 않은 회사면 null — 그때만 작업자에게 직접 묻는다.
     */
    public function employmentType(): ?string
    {
        return match ($this->company_type) {
            self::TYPE_OWN => Employee::TYPE_DIRECT,
            self::TYPE_PARTNER => Employee::TYPE_INDIRECT,
            self::TYPE_CLIENT => Employee::TYPE_CLIENT,
            default => null,
        };
    }

    public function companyTypeLabel(): string
    {
        return self::COMPANY_TYPES[$this->company_type] ?? self::COMPANY_TYPES[self::TYPE_UNKNOWN];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'company_site');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function siteContractors(): HasMany
    {
        return $this->hasMany(SiteContractor::class);
    }

    public function dailyCrewReports(): HasMany
    {
        return $this->hasMany(DailyCrewReport::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function endClientProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'end_client_company_id');
    }

    public function upperContractorProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'upper_contractor_company_id');
    }

    public function epcProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'epc_company_id');
    }

    public function projectContracts(): HasMany
    {
        return $this->hasMany(ProjectContract::class);
    }

    public function counterpartyProjectContracts(): HasMany
    {
        return $this->hasMany(ProjectContract::class, 'counterparty_company_id');
    }
}
