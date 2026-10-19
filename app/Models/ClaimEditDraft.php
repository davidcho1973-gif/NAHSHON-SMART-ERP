<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimEditDraft extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['content' => 'array', 'history' => 'array'];
    }
}
