<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    protected $fillable = [
        'mailbox_connection_id', 'email_thread_id', 'intelligent_document_id', 'provider_message_id',
        'internet_message_id', 'direction', 'sender', 'recipients', 'sent_at', 'received_at',
        'body_preview', 'raw_disk', 'raw_path', 'raw_sha256', 'has_attachments',
    ];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'has_attachments' => 'boolean'];
    }

    public function thread(): BelongsTo { return $this->belongsTo(EmailThread::class, 'email_thread_id'); }
    public function document(): BelongsTo { return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id'); }
}
