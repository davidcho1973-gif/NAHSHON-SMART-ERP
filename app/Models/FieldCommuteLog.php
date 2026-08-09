<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldCommuteLog extends Model
{
    protected $fillable = [
        'site_id',
        'work_date',
        'worker_name',
        'type',
        'scanned_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scanned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
