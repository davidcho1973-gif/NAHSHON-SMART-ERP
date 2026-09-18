<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Equipment extends Model
{
    use HasFactory;

    protected $table = 'equipments';

    protected $fillable = [
        'equipment_code',
        'company_id',
        'site_id',
        'project_id',
        'purchased_for_site_id',
        'team_id',
        'employee_id',
        'equipment_type',
        'category_group',
        'trade',
        'model',
        'vendor',
        'acquisition_type',
        'asset_value',
        'inspection_due_on',
        'rent_start',
        'rent_end',
        'daily_rate',
        'delivery_fee',
        'status',
        'photo_front',
        'photo_rear',
        'photo_left',
        'photo_right',
        'contract_path',
        'registration_method',
        'payload',
        'quantity',
        'is_bulk',
        'qr_token',
        'qr_token_hash',
        'last_checked_at',
    ];

    /**
     * 장비 상태 — 화면·집계가 이 낱말들로 갈린다.
     *
     * «점검필요» 는 사용 전 점검에서 치명 항목이 걸린 장비다. 일부러 사용중·대기중
     * 어느 쪽도 아니게 뒀다: 가동 가능 대수에서 빠지고, 배정 가능 목록에도 안 뜬다.
     * 셋 중 하나로 우겨넣으면 못 쓰는 장비가 «쓸 수 있는 것» 으로 세어진다.
     */
    public const STATUS_IN_USE = '사용중';

    public const STATUS_AVAILABLE = '대기중';

    public const STATUS_NEEDS_INSPECTION = '점검필요';

    /**
     * 대분류 (자재/공구/장비/안전/가설) — 기능 분류의 상위 묶음.
     *
     * @var array<string, string>
     */
    public const CATEGORY_GROUPS = [
        'material' => '자재 (Material)',
        'tool' => '공구 (Tool)',
        'equipment' => '장비 (Equipment)',
        'safety' => '안전 (Safety)',
        'facility' => '가설/시설 (Facility)',
    ];

    /**
     * 기능 분류 (trade) — 플러밍/전기/파워툴/배관툴 등 용도별.
     *
     * @var array<string, string>
     */
    public const TRADES = [
        'plumbing' => '플러밍 (Plumbing)',
        'piping' => '배관 (Piping)',
        'electrical' => '전기 (Electrical)',
        'power_tool' => '파워툴 (Power Tool)',
        'welding' => '용접 (Welding)',
        'measuring' => '측정/계측 (Measuring)',
        'hand_tool' => '수공구 (Hand Tool)',
        'heavy' => '중장비 (Heavy Equipment)',
        'power' => '발전/동력 (Generator & Power)',
        'rigging' => '리깅 (Rigging)',
        'ppe' => '안전용품 (PPE)',
        'general' => '기타 (General)',
    ];

    /**
     * equipment_type(표시 라벨)에서 대분류 + 기능 분류를 추론한다.
     * 신규 등록은 group/trade를 직접 받지만, 기존/AI 등록분은 라벨로 백필한다.
     *
     * @return array{group: string, trade: string}
     */
    public static function classify(?string $equipmentType): array
    {
        $t = mb_strtolower((string) $equipmentType);

        $has = fn (string ...$needles): bool => array_reduce(
            $needles,
            fn (bool $carry, string $n): bool => $carry || str_contains($t, $n),
            false,
        );

        return match (true) {
            $has('plumb', '플러밍') => ['group' => 'tool', 'trade' => 'plumbing'],
            $has('welding', 'weld', '용접') => ['group' => 'equipment', 'trade' => 'welding'],
            $has('generator', '발전', '동력') => ['group' => 'equipment', 'trade' => 'power'],
            $has('heavy', 'boom', 'lift', '중장비', '굴착', '지게차') => ['group' => 'equipment', 'trade' => 'heavy'],
            $has('rigging', '리깅', 'crane', '크레인') => ['group' => 'equipment', 'trade' => 'rigging'],
            $has('safety', 'ppe', '안전') => ['group' => 'safety', 'trade' => 'ppe'],
            $has('measur', 'gauge', '측정', '계측') => ['group' => 'tool', 'trade' => 'measuring'],
            $has('power tool', '전동공구') => ['group' => 'tool', 'trade' => 'power_tool'],
            $has('hand tool', '수공구') => ['group' => 'tool', 'trade' => 'hand_tool'],
            $has('valve', '밸브') => ['group' => 'material', 'trade' => 'piping'],
            $has('pipe', 'fitting', '배관') => ['group' => 'material', 'trade' => 'piping'],
            $has('conduit', 'wire', 'cable', 'electrical', '전선', '전기') => ['group' => 'material', 'trade' => 'electrical'],
            $has('fastener', 'anchor', '체결', '피스') => ['group' => 'material', 'trade' => 'general'],
            $has('container', 'office', '컨테이너', '사무실') => ['group' => 'facility', 'trade' => 'general'],
            default => ['group' => 'material', 'trade' => 'general'],
        };
    }

    /**
     * 저장된 분류가 비어 있으면 라벨에서 추론해 채운다(읽기 시점 폴백).
     */
    public function resolvedGroup(): string
    {
        return $this->category_group ?: self::classify($this->equipment_type)['group'];
    }

    public function resolvedTrade(): string
    {
        return $this->trade ?: self::classify($this->equipment_type)['trade'];
    }

    protected function casts(): array
    {
        return [
            'rent_start' => 'date',
            'rent_end' => 'date',
            'inspection_due_on' => 'date',
            'asset_value' => 'decimal:2',
            'daily_rate' => 'integer',
            'delivery_fee' => 'integer',
            'payload' => 'array',
            'quantity' => 'integer',
            'is_bulk' => 'boolean',
            'qr_token' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Equipment $equipment): void {
            if (blank($equipment->equipment_code)) {
                $equipment->equipment_code = self::makeEquipmentCode();
            }
        });

        // 분류가 비어 있으면 라벨에서 자동 채움(소유/임대 어느 경로로 들어와도 일관).
        static::saving(function (Equipment $equipment): void {
            if (blank($equipment->category_group) || blank($equipment->trade)) {
                $c = self::classify($equipment->equipment_type);
                $equipment->category_group = $equipment->category_group ?: $c['group'];
                $equipment->trade = $equipment->trade ?: $c['trade'];
            }
        });
    }

    private static function makeEquipmentCode(): string
    {
        $max = self::query()
            ->where('equipment_code', 'like', 'EQ-%')
            ->get()
            ->map(function ($v) {
                preg_match('/EQ-(\d+)/', $v->equipment_code, $matches);
                return isset($matches[1]) ? (int)$matches[1] : 0;
            })
            ->max();

        $next = ($max ?? 0) + 1;
        return 'EQ-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * 취득 목적 프로젝트 — "왜 샀나"(고정). 현 위치(site_id)와는 별개.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 취득 목적 현장 — 구매/임대 시점에 배정한 현장(고정).
     */
    public function purchasedForSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'purchased_for_site_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(EquipmentRental::class)->orderByDesc('rented_at');
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(EquipmentRental::class)->where('status', 'active')->whereNull('returned_at');
    }

    public function checklistLogs(): HasMany
    {
        return $this->hasMany(EquipmentChecklistLog::class)->orderByDesc('submitted_at');
    }

    /** 가장 최근 점검 한 건 — «지금 이 장비를 써도 되나» 는 이 줄이 답한다. */
    public function latestChecklistLog(): HasOne
    {
        return $this->hasOne(EquipmentChecklistLog::class)->latestOfMany('submitted_at');
    }

    // ── QR 스티커 ───────────────────────────────────────────────────────
    //
    // 스티커에 장비 번호를 그대로 박으면 번호를 하나씩 올려가며 남의 장비 화면을
    // 열어 볼 수 있다. 출퇴근 QR·배지 QR 과 같은 방식이다: 추측 불가능한 토큰을
    // 쓰고, 원문은 암호화해 두고 대조는 해시로 한다.

    public static function makeQrToken(): string
    {
        return 'eq_'.Str::random(48);
    }

    public static function hashQrToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function forQrToken(string $token): ?self
    {
        return self::query()
            ->with(['site', 'company', 'activeRental'])
            ->where('qr_token_hash', self::hashQrToken($token))
            ->first();
    }

    /**
     * 스티커를 뽑을 수 있게 토큰을 보장한다. 이미 있으면 그대로 둔다 —
     * 다시 만들면 <b>이미 장비에 붙어 있는 스티커가 죽는다.</b>
     */
    public function ensureQrToken(): string
    {
        if (filled($this->qr_token)) {
            return (string) $this->qr_token;
        }

        $token = self::makeQrToken();
        $this->forceFill([
            'qr_token' => $token,
            'qr_token_hash' => self::hashQrToken($token),
        ])->save();

        return $token;
    }

    /**
     * Scope visible query following access-scope rules in AGENTS.md §6-3.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true)
            || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site' => $user->allowed_site_id
                ? $query->where('site_id', $user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            'team' => $user->allowed_team_id
                ? $query->where('team_id', $user->allowed_team_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
