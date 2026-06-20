<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_type',
        'target_type',
        'target_id',
        'provider',
        'model',
        'status',
        'attempts',
        'queued_at',
        'started_at',
        'completed_at',
        'error_message',
        'input_payload',
        'output_payload',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'input_payload' => 'array',
            'output_payload' => 'array',
        ];
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(AiOutput::class);
    }
}
