<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadLocation extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function drawing()
    {
        return $this->belongsTo(CadDrawing::class, 'cad_drawing_id');
    }

    public function sourceRevision()
    {
        return $this->belongsTo(CadDrawingRevision::class, 'source_revision_id');
    }

    public function links()
    {
        return $this->hasMany(CadLocationLink::class);
    }

    public function tasks()
    {
        return $this->hasMany(CadTask::class);
    }
}
