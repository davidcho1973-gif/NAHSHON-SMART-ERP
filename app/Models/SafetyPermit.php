<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 작업허가서(PTW) — 고위험 작업의 발행·승인·서명 문서. 안전 작업카드에 연결된다.
 *
 * @property string $status 발행|승인|서명완료|만료|취소
 */
class SafetyPermit extends Model
{
    protected $fillable = [
        'safety_work_item_id', 'wbs_code', 'site_id', 'permit_no', 'type', 'title',
        'precautions', 'valid_from', 'valid_to', 'status',
        'issued_by_id', 'issued_at', 'approved_by_id', 'approved_at', 'signed_by', 'signed_at', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'precautions' => 'array',
            'payload' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'issued_at' => 'datetime',
            'approved_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkItem::class, 'safety_work_item_id');
    }

    /**
     * 프론트가 기대하는 형태.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'permitNo' => $this->permit_no,
            'workCode' => $this->workItem?->work_code,
            'type' => $this->type,
            'title' => $this->title,
            'precautions' => $this->precautions ?? [],
            'validFrom' => $this->valid_from?->toDateString(),
            'validTo' => $this->valid_to?->toDateString(),
            'status' => $this->status,
            'issuedAt' => $this->issued_at?->toDateTimeString(),
            'approvedAt' => $this->approved_at?->toDateTimeString(),
            'signedBy' => $this->signed_by,
            'signedAt' => $this->signed_at?->toDateTimeString(),
        ];
    }
}
