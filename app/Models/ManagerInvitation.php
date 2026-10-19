<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagerInvitation extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'share_token'];

    protected function casts(): array
    {
        return ['grant' => 'array', 'enrollment' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime',
            'reusable' => 'boolean', 'share_token' => 'encrypted', 'use_count' => 'integer', 'last_used_at' => 'datetime'];
    }
}
