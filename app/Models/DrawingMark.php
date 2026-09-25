<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 도면 번호 위의 표시 하나 — 점·선·영역·메모. 좌표는 쪽 크기 대비 0~1. 색은 연결된 기록에서 나온다. */
class DrawingMark extends Model
{
    public const SHAPES = ['point', 'line', 'area', 'note'];

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['points' => 'array'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ContractBoqLine::class, 'contract_boq_line_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClaimWorkRecord::class, 'claim_work_record_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(WorkSection::class, 'work_section_id');
    }
}
