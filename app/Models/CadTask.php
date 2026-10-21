<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'closed_at' => 'datetime', 'evidence' => 'array', 'version' => 'integer'];
    }

    public function location()
    {
        return $this->belongsTo(CadLocation::class, 'cad_location_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
