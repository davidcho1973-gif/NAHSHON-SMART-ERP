<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 공정별 현장 사진 (날짜별).
 *
 * 공정과는 wbs_code 문자열로 잇는다(안전카드와 같은 방식) — 공정표 교체를 견디기 위해서.
 * 원본은 해시와 함께 별도 보존한다. 화면용 1,600px 이미지와 썸네일은 파생본이다.
 * 도입 전 사진의 original_path 가 null 이면 원본이 보존됐다고 표시하지 않는다.
 */
class WbsPhoto extends Model
{
    protected $fillable = [
        'wbs_code', 'project_code', 'site_id',
        'photo_date', 'caption',
        'disk', 'path', 'thumb_path', 'mime', 'width', 'height',
        'bytes', 'original_bytes', 'original_name', 'uploaded_by_id',
        'original_path', 'original_sha256',
    ];

    protected function casts(): array
    {
        return [
            'photo_date' => 'date',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'original_bytes' => 'integer',
        ];
    }

    public function wbsItem(): BelongsTo
    {
        return $this->belongsTo(WbsItem::class, 'wbs_code', 'wbs_code');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
