<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CadDrawingRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ValidationException::withMessages(['revision' => '저장된 CAD 개정본은 변경할 수 없습니다. 새 개정본을 저장하세요.']);
        });
        static::deleting(function (self $revision): void {
            // Historical drafts and archived claim packets may retain any previous revision.
            throw ValidationException::withMessages(['revision' => '저장된 CAD 개정본 이력은 삭제할 수 없습니다.']);
        });
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(CadDrawing::class, 'cad_drawing_id');
    }

    protected function casts(): array
    {
        return ['warnings' => 'array', 'markups' => 'array'];
    }
}
