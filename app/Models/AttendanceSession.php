<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 하이브리드 출퇴근 하루 세션 — 진입/이탈 상태 + 근무중 구간 합(이탈 차감).
 */
class AttendanceSession extends Model
{
    protected $fillable = [
        'employee_id', 'site_id', 'work_date', 'status',
        'first_enter_at', 'last_enter_at', 'pending_exit_at', 'last_exit_at',
        'on_site_seconds', 'needs_review', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'first_enter_at' => 'datetime',
            'last_enter_at' => 'datetime',
            'pending_exit_at' => 'datetime',
            'last_exit_at' => 'datetime',
            'finalized_at' => 'datetime',
            'on_site_seconds' => 'integer',
            'needs_review' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
