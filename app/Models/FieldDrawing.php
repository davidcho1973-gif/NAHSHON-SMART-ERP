<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldDrawing extends Model
{
    protected $fillable = [
        'site_id',
        'drawing_no',
        'title',
        'category',
        'version',
        'file_path',
        'file_mime',
        'summary',
        'specs',
        'safety_notes',
        'status',
        'ai_model',
        'analysis',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'safety_notes' => 'array',
            'analysis' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FieldDrawingMessage::class);
    }
}
