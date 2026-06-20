<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiOutput extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_job_id',
        'target_type',
        'target_id',
        'output_type',
        'confidence',
        'text',
        'structured_data',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'structured_data' => 'array',
            'embedding' => 'array',
        ];
    }

    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
