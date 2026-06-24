<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyWorkSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'safety_work_item_id', 'name', 'role', 'signed', 'signed_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'signed' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkItem::class, 'safety_work_item_id');
    }
}
