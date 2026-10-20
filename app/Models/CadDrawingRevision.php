<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadDrawingRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['warnings' => 'array'];
    }
}
