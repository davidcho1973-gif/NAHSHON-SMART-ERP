<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadLocationLink extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function location()
    {
        return $this->belongsTo(CadLocation::class, 'cad_location_id');
    }
}
