<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvidenceBatchItem extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'document_id');
    }
}
