<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory;

    /**
     * 주소에서 현장을 찾을 때 <b>번호와 코드를 모두</b> 받는다.
     *
     * 게이트·등록 QR 주소에는 현장 번호(id)가 들어간다. 그런데 그 번호는 데이터베이스
     * 줄 번호라서 환경마다 다르다 — 같은 703K 현장이 여기서는 780, 서버에서는 다른
     * 번호일 수 있다. 벽에 붙는 종이에 그런 값이 들어가면, 옮기거나 다시 세운 환경에서
     * 그 종이가 통째로 죽는다.
     *
     * 현장 코드(703K)는 사람이 정하고 바뀌지 않는 값이다. 그래서 코드로도 찾게 한다.
     * 이미 인쇄된 번호 주소도 계속 동작해야 하므로 <b>번호를 먼저</b> 본다.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $value = is_string($value) ? trim($value) : $value;

        if (is_numeric($value)) {
            $byId = $this->newQuery()->whereKey($value)->first();
            if ($byId) {
                return $byId;
            }
        }

        return $this->newQuery()->whereRaw('upper(code) = ?', [Str::upper((string) $value)])->first();
    }

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
        // 근무 규칙 — 현장마다 다르다(여러 주에 흩어져 있다). 읽는 규칙은 WorkRules 한 곳.
        'work_start',
        'work_end',
        'regular_minutes',
        'break_minutes',
        'break_after_minutes',
        'workweek_days',
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

    public function dailyCrewReports(): HasMany
    {
        return $this->hasMany(DailyCrewReport::class);
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
