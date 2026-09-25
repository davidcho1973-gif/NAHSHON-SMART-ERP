<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 공정이 쓰는 도면 한 장 — 도면 번호로 잇는다. 개정판이 올라와도 선택이 끊기지 않게.
 */
class WorkSectionSheet extends Model
{
    protected $fillable = ['work_section_id', 'sheet_no', 'sort_order', 'created_by_id'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(WorkSection::class, 'work_section_id');
    }
}
