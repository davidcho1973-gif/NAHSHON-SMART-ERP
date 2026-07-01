<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'company_id',
        'code',
        'name',
        'contract_company_name',
        'trade_type',
        'foreman_name',
        'responsible_manager_name',
        'supervisor_name',
        'supervisor_phone',
        'planned_headcount',
        'status',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * 팀의 소속 계약사 이름 — 정규화된 마스터(company_id → companies.name)를 우선하고,
     * 마스터 연결이 없을 때만 자유 입력 텍스트(contract_company_name)를 폴백으로 쓴다.
     * 화면 표시·집계는 항상 이 접근자를 통해 일관된 값을 얻는다.
     */
    public function effectiveCompanyName(): ?string
    {
        return $this->company?->name ?: ($this->contract_company_name ?: null);
    }
}
