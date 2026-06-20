<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'photo_upload_id',
        'engine',
        'status',
        'confidence',
        'raw_text',
        'badge_numbers',
        'employee_matches',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'badge_numbers' => 'array',
            'employee_matches' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function photoUpload(): BelongsTo
    {
        return $this->belongsTo(PhotoUpload::class);
    }
}
