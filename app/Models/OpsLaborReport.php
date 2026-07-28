<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 현장 보고 인원 — "오늘 몇 명 나와서 일했나".
 *
 * 실제 출퇴근(attendance_logs)과 분리되어 있다. 자세한 이유는 마이그레이션 주석 참조.
 */
class OpsLaborReport extends Model
{
    protected $fillable = [
        'site_id', 'ops_intake_batch_id', 'ops_intake_item_id', 'company_id',
        'work_date', 'company_label', 'trade', 'headcount', 'note', 'reported_by_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'headcount' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 화면에 쓸 업체명 — 등록 업체가 있으면 그 이름, 없으면 AI 가 읽은 원문. */
    public function label(): string
    {
        return $this->company?->name ?: ($this->company_label ?: '소속 미상');
    }
}
