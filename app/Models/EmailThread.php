<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    protected $fillable = [
        'mailbox_connection_id', 'owner_user_id', 'company_id', 'site_id', 'project_id',
        'provider_thread_id', 'subject', 'participants', 'first_message_at', 'last_message_at',
        'summary_ko', 'classification', 'needs_response', 'response_due_on', 'ai_confidence',
        'visibility', 'shared_by', 'shared_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array', 'first_message_at' => 'datetime', 'last_message_at' => 'datetime',
            'needs_response' => 'boolean', 'response_due_on' => 'date', 'shared_at' => 'datetime',
            'ai_confidence' => 'decimal:2',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function messages(): HasMany { return $this->hasMany(EmailMessage::class)->orderBy('sent_at'); }
    public function documents(): HasMany { return $this->hasMany(IntelligentDocument::class); }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('owner_user_id', $user->id)
                ->orWhere(function (Builder $shared) use ($user): void {
                    $shared->whereIn('visibility', ['project', 'company'])
                        ->whereHas('documents', fn (Builder $docs) => $docs->visibleTo($user));
                });
        });
    }
}
