<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KakaoRecipient extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'enabled' => 'boolean', 'weekdays' => 'array', 'consented_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
