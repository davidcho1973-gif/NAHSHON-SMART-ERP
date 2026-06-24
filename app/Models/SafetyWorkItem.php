<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafetyWorkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_code', 'company_id', 'site_id', 'team_id',
        'project', 'location', 'title', 'crew', 'unit',
        'planned_qty', 'done_qty', 'total_qty', 'progress', 'due_label',
        'plan_status', 'tbm_status', 'close_status', 'progress_status',
        'work_text', 'close_text', 'plan_payload', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:2',
            'done_qty' => 'decimal:2',
            'total_qty' => 'decimal:2',
            'plan_payload' => 'array',
        ];
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(SafetyWorkSignature::class)->orderBy('sort_order');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SafetyWorkIssue::class)->orderBy('sort_order');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * TBM(툴박스미팅) 게이트 판정 — 공정 진행을 허용해도 되는지.
     * 작업반장이 TBM 을 '완료'로 표기했거나, 서명자 전원이 서명했으면 통과.
     */
    public function isTbmCleared(): bool
    {
        if ($this->tbm_status === '완료') {
            return true;
        }

        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures->isNotEmpty() && $signatures->every(fn (SafetyWorkSignature $s): bool => (bool) $s->signed);
    }

    /**
     * Shape consumed by the renderSafety() SPA (mirrors the old localStorage item).
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->work_code,
            'project' => $this->project ?? '',
            'site' => $this->location ?? '',
            'title' => $this->title,
            'crew' => (int) $this->crew,
            'qty' => (float) $this->planned_qty,
            'unit' => $this->unit ?? '',
            'due' => $this->due_label ?? '',
            'planStatus' => $this->plan_status,
            'tbmStatus' => $this->tbm_status,
            'closeStatus' => $this->close_status,
            'progressStatus' => $this->progress_status,
            'progress' => (int) $this->progress,
            'doneQty' => (float) $this->done_qty,
            'totalQty' => (float) $this->total_qty,
            'workText' => $this->work_text ?? '',
            'closeText' => $this->close_text ?? '',
            'signatures' => $this->signatures->map(fn (SafetyWorkSignature $s): array => [
                'name' => $s->name,
                'role' => $s->role ?? '',
                'signed' => (bool) $s->signed,
                'time' => $s->signed_at?->format('H:i') ?? '-',
            ])->all(),
            'issues' => $this->issues->map(fn (SafetyWorkIssue $i): array => [
                'type' => $i->type,
                'text' => $i->body ?? '',
                'owner' => $i->owner ?? '',
                'status' => $i->status,
            ])->all(),
            'aiPlan' => is_array($this->plan_payload) ? ($this->plan_payload['plan'] ?? null) : null,
            'aiProgress' => is_array($this->plan_payload) ? ($this->plan_payload['progress'] ?? null) : null,
        ];
    }

    /**
     * Map a client JS item to persisted columns (excludes work_code, set on lookup).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function columnsFromClient(array $data): array
    {
        return [
            'project' => $data['project'] ?? null,
            'location' => $data['site'] ?? null,
            'title' => (string) ($data['title'] ?? '제목 없음'),
            'crew' => (int) ($data['crew'] ?? 0),
            'unit' => $data['unit'] ?? null,
            'planned_qty' => (float) ($data['qty'] ?? 0),
            'done_qty' => (float) ($data['doneQty'] ?? 0),
            'total_qty' => (float) ($data['totalQty'] ?? ($data['qty'] ?? 0)),
            'progress' => (int) ($data['progress'] ?? 0),
            'due_label' => $data['due'] ?? null,
            'plan_status' => $data['planStatus'] ?? '미생성',
            'tbm_status' => $data['tbmStatus'] ?? '대기',
            'close_status' => $data['closeStatus'] ?? '시작전',
            'progress_status' => $data['progressStatus'] ?? '미분석',
            'work_text' => $data['workText'] ?? null,
            'close_text' => $data['closeText'] ?? null,
        ];
    }
}
