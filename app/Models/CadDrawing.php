<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CadDrawing extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reference' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $drawing): void {
            if ($drawing->revisions()->exists()) {
                throw ValidationException::withMessages(['drawing' => '저장된 CAD 개정본 이력이 있는 도면은 삭제할 수 없습니다.']);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CadDrawingRevision::class);
    }
}
