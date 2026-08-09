<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * 공정관리(WBS) 노드 — Stage / Task / SubTask 를 단일 자기참조 트리로 표현.
 *
 * @property string $level stage|task|subtask
 * @property string $status AI생성|검수완료|진행중|완료|보류
 */
class WbsItem extends Model
{
    use HasFactory;

    public const LEVEL_STAGE = 'stage';

    public const LEVEL_TASK = 'task';

    public const LEVEL_SUBTASK = 'subtask';

    /** 완료로 간주되는 상태 (진척률 100%). */
    public const STATUS_DONE = '완료';

    public const STATUS_IN_PROGRESS = '진행중';

    protected $fillable = [
        'project_id', 'project_code', 'parent_id', 'level', 'wbs_code', 'node_no', 'activity_id', 'name',
        'company', 'trade', 'crew_text', 'crew_size', 'crew_roles', 'equipment', 'status', 'ehs', 'manhours', 'days',
        'planned_start', 'planned_end', 'preds', 'float_days', 'is_critical', 'late_start', 'late_end',
        'planned_cost', 'progress', 'sort_order', 'company_id', 'site_id', 'source', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'manhours' => 'decimal:1',
            'days' => 'integer',
            'progress' => 'integer',
            'sort_order' => 'integer',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'late_start' => 'date',
            'late_end' => 'date',
            'preds' => 'array',
            'float_days' => 'integer',
            'is_critical' => 'boolean',
            'planned_cost' => 'decimal:2',
            'crew_size' => 'decimal:1',
            'crew_roles' => 'array',
            'equipment' => 'array',
            'payload' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * 안전 작업카드들 (병합 지점, 느슨한 코드 기반 — FK 없음).
     *
     * 1:N 인 이유: 안전(TBM/서명)은 하루 단위 행위다. 3일짜리 작업은 카드가 3장 나온다.
     */
    public function safetyWorkItems(): HasMany
    {
        return $this->hasMany(SafetyWorkItem::class, 'wbs_code', 'wbs_code')->orderBy('work_date');
    }

    /**
     * 오늘(또는 지정일) 카드 — TBM 게이트는 "그날의 카드"를 본다.
     */
    public function safetyCardFor(string $date): ?SafetyWorkItem
    {
        return $this->loadedCards()->first(fn (SafetyWorkItem $c) => (string) $c->work_date?->toDateString() === $date);
    }

    /**
     * SubTask 노드의 진척률(%) — 상태 우선, 연결된 안전카드가 있으면 현장 실측을 반영.
     *
     * 카드가 여러 장이면 물량 기준으로 합산한다(하루치씩 쌓인 done_qty 의 합 ÷ 총 물량).
     * 물량이 없는 카드만 있으면 진행률 최대값으로 폴백한다.
     */
    public function effectiveProgress(): int
    {
        if ($this->status === self::STATUS_DONE) {
            return 100;
        }

        $cards = $this->loadedCards();
        if ($cards->isEmpty()) {
            return (int) $this->progress;
        }

        $totalQty = (float) $cards->max('total_qty');
        if ($totalQty > 0) {
            $done = (float) $cards->sum('done_qty');

            return max((int) $this->progress, min(100, (int) round($done / $totalQty * 100)));
        }

        return max((int) $this->progress, (int) $cards->max('progress'));
    }

    /**
     * 조달성 공정인가 — 발주·조달·구매·제작처럼 "사람이 몸으로 하는 현장 작업"이 아니라
     * 물건을 사서 납기를 기다리는 공정. (인원이 배정된 작업은 조달로 보지 않는다.)
     *
     * 이런 공정은 '오늘 할 일'(현장 노무) 대신 '조달 관리'(자재 납기)에서 다룬다.
     */
    public function looksLikeProcurement(): bool
    {
        if ($this->crew_size !== null && (float) $this->crew_size > 0) {
            return false;
        }

        $hay = mb_strtolower(trim(($this->name ?? '').' '.($this->trade ?? '')));
        if ($hay === '') {
            return false;
        }

        foreach (['조달', '발주', '구매', '납품', '제작', '조립생산', 'procure', 'purchase', 'lead time', '리드타임'] as $kw) {
            if (str_contains($hay, mb_strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 이 공정에 실제로 투입된 인원 수 (안전카드 서명 기준). 계획(crew_size)과 대비된다.
     */
    public function actualCrewCount(): int
    {
        return (int) $this->loadedCards()->sum(fn (SafetyWorkItem $c) => $c->signatures->where('signed', true)->count());
    }

    /**
     * @return Collection<int, SafetyWorkItem>
     */
    private function loadedCards(): Collection
    {
        return $this->relationLoaded('safetyWorkItems') ? $this->safetyWorkItems : collect();
    }

    /**
     * 프론트(renderWbs)의 sub_task 형태로 변환.
     *
     * @return array<string, mixed>
     */
    public function toSubtaskArray(?string $today = null): array
    {
        $today ??= now()->toDateString();
        $cards = $this->loadedCards();
        $todayCard = $this->safetyCardFor($today);

        // 게이트: 그날 카드가 있으면 그 카드의 TBM 을 본다. 카드가 아직 없으면 "안전계획 필요" 상태.
        $tbmGated = $todayCard !== null && ! $todayCard->isTbmCleared();

        return [
            'wbs_id' => $this->wbs_code,
            'activity_id' => $this->activity_id,
            'sub_no' => $this->node_no ?? '',
            'sub_name' => $this->name,
            'trade' => $this->trade ?? '',
            'company' => $this->company ?? '',
            'manhours' => (float) $this->manhours,
            'days' => (int) $this->days,
            'status' => $this->status,
            'ehs' => $this->ehs ?? '',
            // 편집 모달이 조달용/작업용 폼을 가른다 — 조달에는 투입조·장비·EHS 칸이 무의미하다.
            'isProcurement' => $this->looksLikeProcurement(),
            'plannedStart' => $this->planned_start?->toDateString(),
            'plannedEnd' => $this->planned_end?->toDateString(),
            'progress' => $this->effectiveProgress(),
            // CPM
            'preds' => $this->preds ?? [],
            'floatDays' => $this->float_days,
            'isCritical' => (bool) $this->is_critical,
            'plannedCost' => $this->planned_cost !== null ? (float) $this->planned_cost : null,
            // 인원 · 장비 (안전계획의 입력값)
            'crewText' => $this->crew_text,
            'crewSize' => $this->crew_size !== null ? (float) $this->crew_size : null,
            'crewRoles' => $this->crew_roles ?? [],
            'equipment' => $this->equipment ?? [],
            'actualCrew' => $this->actualCrewCount(),
            // 안전 연동
            'safetyCardCount' => $cards->count(),
            'safetyWorkCode' => $todayCard?->work_code,
            'tbmGated' => $tbmGated,
            'safetyTbmStatus' => $todayCard?->tbm_status,
            'hasCardToday' => $todayCard !== null,
        ];
    }
}
