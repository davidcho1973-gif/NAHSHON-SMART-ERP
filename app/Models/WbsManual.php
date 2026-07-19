<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbsManual extends Model
{
    protected $fillable = [
        'project_code',
        'site_id',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
        'engine',
        'status',
        'stages',
        'tasks',
        'subtasks',
        'error',
        'analyzed_by_id',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'stages' => 'integer',
            'tasks' => 'integer',
            'subtasks' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function analyzedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by_id');
    }
}
