<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CadAiSession extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['editor_token_hash', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'allow_edits' => 'boolean', 'expires_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(CadDrawing::class, 'drawing_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(CadAiCommand::class, 'session_id');
    }
}
