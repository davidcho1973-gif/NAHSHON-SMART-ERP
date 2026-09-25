<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 문서함 도면 PDF 의 한 쪽 — 「06_배관 파일 3쪽은 703K-P301」.
 *
 * 파일 자체는 문서함(IntelligentDocument)에 있다. 여기는 쪽마다의 도면 번호·제목과,
 * 그 쪽에서 읽은 글자만 둔다. 사진으로 된 쪽은 AI 가 글자를 읽어 붙인다(text_source = ocr).
 */
class DrawingSheet extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READING = 'reading';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'intelligent_document_id', 'site_id', 'page_no', 'sheet_no', 'title', 'discipline', 'manual',
        'text_source', 'text', 'status', 'error', 'ai_model',
        'thumb_disk', 'thumb_path', 'width_pt', 'height_pt', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
            'width_pt' => 'float',
            'height_pt' => 'float',
            'read_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** 도면 번호 비교는 대소문자·공백을 무시한다 — 「703k-a01-01」 과 「703K-A01-01 」 은 같은 장이다. */
    public static function normalizeNo(?string $no): ?string
    {
        $no = strtoupper(trim((string) preg_replace('/\s+/', '', (string) $no)));

        return $no !== '' ? mb_substr($no, 0, 40) : null;
    }
}
