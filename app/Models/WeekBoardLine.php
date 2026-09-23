<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 이번 주 작업판의 한 줄 — 「배관 2명 — 급탕 배관」.
 *
 * 현장이 자기 말로 적는 한 주의 일. 정식 공정표(WbsItem)와는 별개의 사실이다.
 */
class WeekBoardLine extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_DOING = 'doing';

    public const STATUS_DONE = 'done';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_PLANNED => '예정',
        self::STATUS_DOING => '진행중',
        self::STATUS_DONE => '완료',
        self::STATUS_BLOCKED => '못함',
    ];

    protected $fillable = [
        'company_id', 'site_id', 'week_start', 'trade', 'task', 'headcount',
        'status', 'reason', 'note', 'wbs_codes', 'sort_order', 'carried_from_id',
        'created_by_id', 'updated_by_id', 'done_at',
        // 상황실 글이 저절로 바꾼 흔적 — 사람이 버튼을 누르면 지워진다.
        'auto_source', 'auto_quote', 'auto_batch_id', 'auto_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'headcount' => 'decimal:1',
            'wbs_codes' => 'array',
            'done_at' => 'datetime',
            'auto_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /** 접근제어 — 다른 현장 표와 같은 규칙(AGENTS.md §6-3). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true)
            || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site', 'team' => $user->allowed_site_id
                ? $query->where('site_id', $user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
