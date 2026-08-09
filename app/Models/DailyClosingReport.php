<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 일일 마감 보고서. 집계(metrics)는 DB 에서 뽑은 확정 숫자, 서술(narrative)은 AI 가 쓴 문장.
 */
class DailyClosingReport extends Model
{
    protected $fillable = [
        'site_id', 'report_date', 'status', 'error',
        'metrics', 'narrative', 'closed_by_id', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'metrics' => 'array',
            'narrative' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}
