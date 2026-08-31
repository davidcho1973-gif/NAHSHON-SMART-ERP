<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 보고서를 누구에게 언제 보냈는가 — 발송의 증거.
 *
 * "보냈습니다" 라는 말만 남으면 나중에 원청이 "못 받았다" 고 할 때 아무것도
 * 대지 못한다. 실패도 그대로 남긴다. 메일 설정이 아직 없어 사장님 메일앱으로
 * 넘긴 경우는 `channel='mailto'` 로, 보낸 척하지 않는다.
 */
class ReportDispatch extends Model
{
    protected $fillable = [
        'daily_closing_report_id', 'kind', 'channel',
        'to_email', 'to_name', 'subject', 'status', 'error',
        'intelligent_document_id', 'created_by_id', 'sent_at',
        // 서신 원장의 어느 봉투인가. fillable 에 없으면 대량할당이 조용히 버린다.
        'mail_message_id',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyClosingReport::class, 'daily_closing_report_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }
}
