<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 봉투 한 통 — 전사 서신 원장의 정본.
 *
 * 보낸 것·못 보낸 것·메일앱으로 넘긴 것이 한 표에 모인다. <b>실패도 남긴다.</b>
 * 성공만 기록하는 원장은 나중에 "우리가 보냈다" 를 증명하지 못한다 — 상대가 "못 받았다"
 * 고 할 때 내밀 것이 필요하고, 그때 필요한 건 성공 기록이 아니라 <b>전부</b>다.
 */
class MailMessage extends Model
{
    /** 실제로 메일 서버를 통해 나갔다. */
    public const SENT = 'sent';

    /** 발송을 시도했으나 실패했다. */
    public const FAILED = 'failed';

    /** 메일 설정이 없어 사람 메일앱으로 넘겼다 — 보낸 척하지 않는다. */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'mail_thread_id', 'company_id', 'site_id',
        'direction', 'channel', 'status',
        'provider', 'provider_message_id', 'rfc_message_id', 'in_reply_to', 'references_raw',
        'from_address', 'from_name', 'to_addresses', 'cc_addresses',
        'subject', 'body_text', 'body_html', 'snippet', 'attachment_count',
        'error', 'delivered_at', 'bounced_at', 'bounce_reason',
        'occurred_at', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'occurred_at' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
            'attachment_count' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'mail_thread_id');
    }

    /** 이 메일에 실린 첨부 — 문서함의 문서를 가리킨다. */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            IntelligentDocument::class, 'mail_message_documents',
            'mail_message_id', 'intelligent_document_id',
        )->withPivot('kind')->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::SENT => '발송',
            self::FAILED => '실패',
            self::SKIPPED => '메일앱',
            'received' => '수신',
            'delivered' => '도달',
            'bounced' => '반송',
            default => $this->status,
        };
    }

    /** 받는 사람을 한 줄로. */
    public function recipientLine(): string
    {
        $to = implode(', ', (array) ($this->to_addresses ?: []));
        $cc = (array) ($this->cc_addresses ?: []);

        return $to.($cc !== [] ? ' (참조 '.count($cc).'명)' : '');
    }
}
