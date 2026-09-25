<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 계약서의 공정 한 묶음 — 「3) DRYWALL」「M1-2. Duct Work」.
 *
 * 기성관리가 공정관리다(사장). 그래서 공정의 이름과 금액은 원청 계약 기성표의 섹션이다.
 * 계약 행(수량·단가)이 올라오면 이 아래에 붙는다.
 */
class WorkSection extends Model
{
    protected $fillable = [
        'site_id', 'company_id', 'division', 'code', 'name', 'contract_amount', 'sort_order', 'source',
    ];

    protected function casts(): array
    {
        return ['contract_amount' => 'float'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(WorkSectionSheet::class)->orderBy('sort_order')->orderBy('id');
    }
}
