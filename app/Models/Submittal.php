<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 제출물 대장 한 행 — 시방·도면이 요구하는 제출물/QA/시험 항목 하나.
 *
 * title 에 원문 조항이 병기되어 있고, gate=true 는 시방에 금지·실격·입회 등
 * 강제 조항이 걸린 우선관리 항목이다. 상태만 현장에서 갱신한다.
 */
class Submittal extends Model
{
    public const STATUS_OPTIONS = [
        '미착수' => '미착수',
        '작성중' => '작성중',
        '제출' => '제출',
        '승인' => '승인',
        '조건부승인' => '조건부승인',
        '반려' => '반려',
        '재제출' => '재제출',
    ];

    public const CATEGORY_OPTIONS = [
        'Action 제출물' => 'Action 제출물',
        'Informational 제출물' => 'Informational 제출물',
        'Closeout 제출물' => 'Closeout 제출물',
        '품질보증(QA)' => '품질보증(QA)',
        '시험·검사' => '시험·검사',
    ];

    protected $fillable = [
        'company_id', 'site_id', 'project_id',
        'seq', 'csi', 'section', 'category', 'title', 'gate',
        'status', 'assignee', 'planned_on', 'submitted_on', 'approved_on', 'notes',
        // 소통 상대 — 자료를 주는 업체와 자료를 최종 받는 원청·감리.
        'vendor_name', 'vendor_email', 'vendor_phone', 'recipient_name', 'recipient_email',
        // 시방 추출로 들어온 줄이 달고 오는 것들
        'confidence', 'needs_review', 'review_reason', 'source_document_id', 'extracted_by', 'source_excerpt',
    ];

    protected function casts(): array
    {
        return [
            'gate' => 'boolean',
            'needs_review' => 'boolean',
            'confidence' => 'integer',
            'planned_on' => 'date',
            'submitted_on' => 'date',
            'approved_on' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 이 줄을 뽑아 온 원본 문서 — 시방서·도면.
     *
     * 추출할 때 AI 가 이미 어느 문서의 어느 문장인지 적어 두었다(source_excerpt).
     * 나중에 검색으로 되찾는 것은 추측이지만 이것은 기록이다 — 대장에서 조항을
     * 누르면 그 문서로 바로 갈 수 있어야 한다.
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'source_document_id');
    }

    /** 이 조항을 채우는 자료들 — 제품자료·시험성적·승인본. 출처(시방)와는 다른 방향이다. */
    public function documents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(IntelligentDocument::class, 'submittal_documents')
            ->withPivot('kind')
            ->withTimestamps();
    }

    /** 소통 이력 — 요청·연결·전달·승인이 시간순으로 남는다. */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SubmittalEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** 마지막 소통 한 줄 — 목록이 "지금 어디까지 왔나" 를 보여줄 때 쓴다. */
    public function lastEvent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SubmittalEvent::class)->latestOfMany();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
