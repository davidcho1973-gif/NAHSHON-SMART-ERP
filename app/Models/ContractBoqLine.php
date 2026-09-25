<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractBoqLine extends Model
{
    protected $guarded = ['id'];

    // PostgreSQL timestamptz must receive the instant's offset, not local wall time.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['contract_qty' => 'decimal:4', 'unit_price' => 'decimal:4', 'material_price' => 'decimal:4', 'labor_price' => 'decimal:4', 'expense_price' => 'decimal:4', 'stage_weights' => 'array', 'accepted_at' => 'datetime'];
    }

    /** 계약서의 공정 묶음(「3) DRYWALL」) — 기성관리가 공정관리이므로 줄이 자기 공정을 안다. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(WorkSection::class, 'work_section_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ClaimWorkRecord::class, 'contract_boq_line_id');
    }
}
