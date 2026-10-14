<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentEvidenceBatch extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['match_cursor' => 'integer', 'match_inflight' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentEvidenceBatchItem::class, 'batch_id');
    }
}
