<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 현장 액션 아이템 — 공정·자재·인원 어디에도 안 들어가는 내용의 종착지.
 * (원청 지시, 승인 요청, 의사결정 대기, 준비물, 회신 요청)
 */
class OpsActionItem extends Model
{
    /** 원청·상급자의 지시나 요청. */
    public const KIND_REQUEST = 'request';

    /** 승인 요청과 그 결과(연장작업, 화기작업 등). */
    public const KIND_APPROVAL = 'approval';

    /** 결정이 필요한 사항(금액 협상, 업체 선정 등). */
    public const KIND_DECISION = 'decision';

    /** 준비물·잡무. */
    public const KIND_TODO = 'todo';

    /** 참고 공유(결정도 조치도 필요 없음). */
    public const KIND_INFO = 'info';

    public const KIND_LABELS = [
        self::KIND_REQUEST => '지시·요청',
        self::KIND_APPROVAL => '승인',
        self::KIND_DECISION => '의사결정',
        self::KIND_TODO => '준비·조치',
        self::KIND_INFO => '공유',
    ];

    protected $fillable = [
        'site_id', 'ops_intake_batch_id', 'ops_intake_item_id',
        'kind', 'title', 'detail', 'requester', 'assignee',
        'due_on', 'occurred_on', 'status', 'is_blocker', 'done_at', 'done_by_id',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'occurred_on' => 'date',
            'is_blocker' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
