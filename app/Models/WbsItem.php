<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 공정관리(WBS) 노드 — Stage / Task / SubTask 를 단일 자기참조 트리로 표현.
 *
 * @property string $level  stage|task|subtask
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
        'project_id', 'project_code', 'parent_id', 'level', 'wbs_code', 'node_no', 'name',
        'company', 'status', 'ehs', 'manhours', 'days', 'planned_start', 'planned_end',
        'progress', 'sort_order', 'company_id', 'site_id', 'safety_work_code', 'source', 'payload',
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
     * 안전 작업카드 링크 (병합 지점, 느슨한 코드 기반 — FK 없음).
     */
    public function safetyWorkItem(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkItem::class, 'safety_work_code', 'work_code');
    }

    /**
     * SubTask 노드의 진척률(%) — 상태 우선, 연결된 안전카드가 있으면 그 진행률을 반영.
     */
    public function effectiveProgress(): int
    {
        if ($this->status === self::STATUS_DONE) {
            return 100;
        }

        // 안전 작업카드가 연결돼 있고 진행률이 더 크면 그것을 신뢰(현장 실측).
        $linked = $this->relationLoaded('safetyWorkItem') ? $this->safetyWorkItem : null;
        $linkedProgress = $linked ? (int) $linked->progress : 0;

        return max((int) $this->progress, $linkedProgress);
    }

    /**
     * 프론트(renderWbs)의 sub_task 형태로 변환.
     *
     * @return array<string, mixed>
     */
    public function toSubtaskArray(): array
    {
        return [
            'wbs_id' => $this->wbs_code,
            'sub_no' => $this->node_no ?? '',
            'sub_name' => $this->name,
            'company' => $this->company ?? '',
            'manhours' => (float) $this->manhours,
            'days' => (int) $this->days,
            'status' => $this->status,
            'ehs' => $this->ehs ?? '',
            'progress' => $this->effectiveProgress(),
            'safetyWorkCode' => $this->safety_work_code,
        ];
    }
}
