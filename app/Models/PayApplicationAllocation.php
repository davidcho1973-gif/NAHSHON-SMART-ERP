<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayApplicationAllocation extends Model
{
    protected $guarded = ['id'];

    // PostgreSQL timestamptz must receive the instant's offset, not local wall time.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'amount' => 'decimal:2', 'snapshot' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PayApplication::class, 'pay_application_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClaimWorkRecord::class, 'claim_work_record_id');
    }
}
