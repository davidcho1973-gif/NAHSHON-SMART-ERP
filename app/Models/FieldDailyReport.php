<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDailyReport extends Model
{
    protected $fillable = [
        'site_id',
        'work_date',
        'weather',
        'temperature',
        'trades',
        'work_title',
        'work_today',
        'work_tomorrow',
        'progress_rate',
        'tbm_completed',
        'safety_checks',
        'safety_notes',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'trades' => 'array',
            'tbm_completed' => 'boolean',
            'safety_checks' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
