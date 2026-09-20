<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsMeeting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meeting_on' => 'date', 'parts' => 'array', 'transcripts' => 'array',
            'analysis' => 'array', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpsIntakeBatch::class, 'ops_intake_batch_id');
    }
}
