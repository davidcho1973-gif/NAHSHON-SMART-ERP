<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 공종별 일일보고 한 장 — "배관, 8월 31일".
 *
 * 상태는 둘뿐이다: 쓰는 중(open) · 제출됨(submitted). 셋 이상으로 나누면 현장에서
 * 아무도 그 차이를 기억하지 못한다.
 */
class DailyTradeReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SUBMITTED = 'submitted';

    /** 공종 보고 — 반장·기사의 몫. */
    public const KIND_TRADE = 'trade';

    /** 부서 보고 — 사무·안전·현장관리·공무처럼 공종이 없는 관리자의 몫. */
    public const KIND_OFFICE = 'office';

    protected $fillable = [
        'site_id', 'work_date', 'trade', 'kind', 'status',
        'submitted_by_id', 'submitted_at', 'reopen_reason',
        'applied_count', 'held_count', 'reflected_at', 'reflection_note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'submitted_at' => 'datetime',
            'reflected_at' => 'datetime',
            'applied_count' => 'integer',
            'held_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** 이 보고에 묶인 현장 기록(글·사진). */
    public function batches(): HasMany
    {
        return $this->hasMany(OpsIntakeBatch::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }
}
