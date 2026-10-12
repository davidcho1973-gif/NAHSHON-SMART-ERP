<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Documentary associations only. Never a measurement, expense approval or claim amount. */
class ClaimEvidenceInboxItem extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'line_ids' => 'array', 'locators' => 'array', 'review_history' => 'array',
            'source_date' => 'date', 'source_updated_at' => 'datetime',
            'reviewed_at' => 'datetime', 'last_matched_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }
}
