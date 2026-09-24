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
        return ['contract_qty' => 'decimal:4', 'unit_price' => 'decimal:4', 'stage_weights' => 'array', 'accepted_at' => 'datetime'];
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
