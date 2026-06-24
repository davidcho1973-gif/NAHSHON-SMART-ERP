<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyWorkIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'safety_work_item_id', 'type', 'body', 'owner', 'status', 'sort_order',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkItem::class, 'safety_work_item_id');
    }
}
