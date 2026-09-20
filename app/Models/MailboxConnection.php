<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxConnection extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'provider', 'provider_user_id', 'email', 'access_token',
        'refresh_token', 'token_expires_at', 'selected_folders', 'sync_cursor', 'status',
        'last_synced_at', 'last_error_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'selected_folders' => 'array',
            'sync_cursor' => 'array',
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function threads(): HasMany { return $this->hasMany(EmailThread::class); }
    public function messages(): HasMany { return $this->hasMany(EmailMessage::class); }
}
