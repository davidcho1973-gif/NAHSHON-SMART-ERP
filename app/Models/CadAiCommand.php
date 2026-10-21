<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CadAiCommand extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['actions', 'result'];

    protected function casts(): array
    {
        return ['actions' => 'array', 'result' => 'array', 'expires_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CadAiSession::class, 'session_id');
    }
}
