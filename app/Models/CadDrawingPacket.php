<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CadDrawingPacket extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        $immutable = static function (): never {
            throw ValidationException::withMessages(['packet' => '보관한 검토본은 변경하거나 삭제할 수 없습니다. 새 검토본을 만드세요.']);
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['manifest' => 'array', 'created_at' => 'datetime'];
    }

    public function revision()
    {
        return $this->belongsTo(CadDrawingRevision::class, 'cad_drawing_revision_id');
    }
}
