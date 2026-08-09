<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDrawingMessage extends Model
{
    protected $fillable = [
        'field_drawing_id',
        'role',
        'content',
        'sources',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
        ];
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(FieldDrawing::class, 'field_drawing_id');
    }
}
