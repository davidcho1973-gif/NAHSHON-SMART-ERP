<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CadLibraryPart extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $part): void {
            if (array_diff(array_keys($part->getDirty()), ['archived', 'updated_at'])) {
                throw new \LogicException('공용 부품 정의는 수정할 수 없습니다. 새 부품으로 등록하세요.');
            }
        });
        static::deleting(fn () => throw new \LogicException('공용 부품은 삭제 대신 사용 중지하세요.'));
    }

    protected function casts(): array
    {
        return ['geometry' => 'array', 'archived' => 'boolean'];
    }
}
