<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagerInvitation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['grant' => 'array', 'enrollment' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
