<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 앱 «물어보기» 의 질문 한 건과 그 답 — 물어본 사람만 본다.
 */
class DocumentQuestion extends Model
{
    protected $fillable = [
        'user_id', 'site_id', 'question', 'answer', 'found', 'sources', 'denied', 'model',
    ];

    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'sources' => 'array',
            'denied' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
