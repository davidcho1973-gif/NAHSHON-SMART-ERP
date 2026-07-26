<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 관리자가 직접 만든 문서 폴더. 기본 9개 폴더(IntegratedDocument::FOLDERS)에 이어 붙는다.
 */
class DocumentFolder extends Model
{
    protected $fillable = ['code', 'name', 'color', 'sort_order', 'created_by_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** 다음 폴더 코드 — 기본 폴더(01~09) 다음인 '10' 부터 순차 부여. */
    public static function nextCode(): string
    {
        $max = 9;
        foreach (self::query()->pluck('code') as $code) {
            if (is_numeric($code)) {
                $max = max($max, (int) $code);
            }
        }

        return str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }
}
