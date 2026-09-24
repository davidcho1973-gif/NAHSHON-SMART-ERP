<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimWorkRecord extends Model
{
    protected $guarded = ['id'];

    // PostgreSQL timestamptz must receive the instant's offset, not local wall time.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['work_date' => 'date', 'reported_qty' => 'decimal:4', 'verified_qty' => 'decimal:4', 'evidence' => 'array', 'review_history' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ContractBoqLine::class, 'contract_boq_line_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PayApplicationAllocation::class);
    }
}
