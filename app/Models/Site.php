<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    /** 글로벌 인원·출퇴근 현황의 국가 분류 (ISO-2). */
    public const COUNTRY_OPTIONS = [
        'US' => '🇺🇸 United States',
        'KR' => '🇰🇷 Korea',
        'CA' => '🇨🇦 Canada',
    ];

    protected $fillable = [
        'company_id',
        'client_company_id',
        'code',
        'name',
        'country',
        'address',
        'timezone',
        'status',
        'payload',
        'setup_completed_at',
        'manager_employee_id',
        'latitude',
        'longitude',
        'radius_meters',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'setup_completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 원청사 (발주처/원청 — 그 현장의 client). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'client_company_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_site');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function contractors(): HasMany
    {
        return $this->hasMany(SiteContractor::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function communicationRooms(): HasMany
    {
        return $this->hasMany(CommunicationRoom::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function projectContracts(): HasMany
    {
        return $this->hasMany(ProjectContract::class);
    }
}
