<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 상황실에 한 번에 넣은 원문 뭉치(붙여넣은 대화 전체). 판독 결과의 근거로 보관된다.
 */
class OpsIntakeBatch extends Model
{
    protected $fillable = [
        'site_id', 'created_by_id', 'source', 'communication_message_id',
        'raw_text', 'image_count', 'parsed_count', 'actionable_count', 'noise_count',
    ];

    protected function casts(): array
    {
        return [
            'image_count' => 'integer',
            'parsed_count' => 'integer',
            'actionable_count' => 'integer',
            'noise_count' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpsIntakeItem::class, 'ops_intake_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** 목록에 보여줄 짧은 미리보기. */
    public function preview(int $len = 90): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $this->raw_text) ?? '');

        return $text === '' ? '(사진만 첨부)' : mb_substr($text, 0, $len) . (mb_strlen($text) > $len ? '…' : '');
    }
}
