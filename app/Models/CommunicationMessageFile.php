<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 채팅 메시지에 붙은 파일 한 개. 문서함 문서와 이어져 있다면 그쪽이 분석·배달을 맡는다.
 */
class CommunicationMessageFile extends Model
{
    use HasFactory;

    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    protected $fillable = [
        'communication_message_id',
        'intelligent_document_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'file_size',
        'kind',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    /** 사람이 읽는 크기 — 목록에서 "2.4 MB" 처럼 보이게. */
    public function humanSize(): string
    {
        $bytes = (int) $this->file_size;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
