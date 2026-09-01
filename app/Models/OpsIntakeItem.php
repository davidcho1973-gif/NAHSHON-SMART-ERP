<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 현장 상황실 판독 결과 1건 — "이 말은 이런 뜻이고, 공정표의 이걸 이렇게 바꾸면 된다"는 제안.
 */
class OpsIntakeItem extends Model
{
    /** 사람이 읽는 분류 라벨. */
    public const CATEGORY_LABELS = [
        'progress' => '작업 마감·진행',
        'plan' => '작업 계획',
        'procurement' => '자재·조달',
        'labor' => '인력·출역',
        'expense' => '지출·영수증',
        'issue' => '이슈·안전',
        'inspection' => '검사·검측',
        'request' => '지시·요청',
        'approval' => '승인',
        'decision' => '의사결정',
        'todo' => '준비·조치',
        'noise' => '잡담',
    ];

    /** 공정·자재·인원 어디에도 안 들어가고 액션 아이템으로 가는 분류. */
    public const ACTION_CATEGORIES = ['request', 'approval', 'decision', 'todo'];

    protected $fillable = [
        'site_id', 'ops_intake_batch_id', 'project_code', 'source', 'communication_message_id', 'created_by_id',
        'raw_text', 'speaker', 'occurred_on',
        'category', 'confidence', 'summary',
        'target_type', 'target_code', 'target_name',
        'proposed', 'previous', 'question', 'conflict',
        'status', 'applied_at', 'applied_by_id', 'result_note', 'applied_via',
    ];

    /** 상황실에서 사람이 「반영」을 눌렀다. */
    public const VIA_MANUAL = 'manual';

    /** 반장이 「오늘 보고 제출」을 눌러 자동으로 넘어갔다. */
    public const VIA_REPORT = 'report';

    /** 판독 직후 바로 모듈로 갔다(인원 보고·액션 아이템). */
    public const VIA_AUTO = 'auto';

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'confidence' => 'integer',
            'proposed' => 'array',
            'conflict' => 'array',
            'previous' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpsIntakeBatch::class, 'ops_intake_batch_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    /** 잡담이 아니고 아직 처리되지 않은 것만 관리자에게 보여준다. */
    public function isActionable(): bool
    {
        return $this->category !== 'noise' && in_array($this->status, ['pending', 'needs_input'], true);
    }
}
