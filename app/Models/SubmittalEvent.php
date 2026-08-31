<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 제출물 소통 기록 한 줄 — 요청 보냄 / 자료 연결 / 원청 전달 / 승인본 연결.
 *
 * 이 기록이 있어야 "조항 → 요청서 → 제출본 → 승인본" 이 한 줄로 나온다.
 * 지우지 않는다 — 소통 이력은 나중에 분쟁이 났을 때의 증거다.
 */
class SubmittalEvent extends Model
{
    public const KIND_LABELS = [
        'request_sent' => '업체에 자료 요청',
        'materials_linked' => '받은 자료 연결',
        'transmitted' => '원청에 전달',
        'approval_linked' => '승인본 연결',
        'note' => '메모',
    ];

    protected $fillable = [
        'submittal_id', 'kind', 'channel', 'to_name', 'to_email', 'subject',
        'intelligent_document_id', 'created_by',
        // 서신 원장의 어느 봉투인가. 이 칸을 fillable 에 안 넣으면 대량할당이
        // 예외 없이 조용히 버려서, 연결이 끊긴 줄 아무도 모른다.
        'mail_message_id',
    ];

    public function submittal(): BelongsTo
    {
        return $this->belongsTo(Submittal::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }
}
