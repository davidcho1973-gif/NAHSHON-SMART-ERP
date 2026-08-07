<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentActionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'intelligent_document_id', 'company_id', 'site_id', 'project_id', 'assigned_user_id',
        'action_type', 'related_module', 'severity', 'status', 'title', 'details', 'recommended_action',
        'source_excerpt', 'due_at', 'remind_at', 'completed_at', 'confidence', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'remind_at' => 'datetime',
            'completed_at' => 'datetime',
            'confidence' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
