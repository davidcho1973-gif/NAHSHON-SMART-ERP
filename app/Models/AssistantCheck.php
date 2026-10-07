<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantCheck extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'interval_hours' => 'integer', 'approved_at' => 'datetime', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime', 'last_result' => 'array'];
    }
}
