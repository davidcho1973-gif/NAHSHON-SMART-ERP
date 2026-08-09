<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 공정별 현장 사진 (날짜별).
 *
 * 공정과는 wbs_code 문자열로 잇는다(안전카드와 같은 방식) — 공정표 교체를 견디기 위해서.
 * 저장되는 것은 항상 축소본이다: 업로드 시 장변 1,600px JPEG 로 줄이고,
 * 목록용 썸네일(400px)을 따로 굽는다. 원본 크기는 original_bytes 로만 남는다.
 */
class WbsPhoto extends Model
{
    protected $fillable = [
        'wbs_code', 'project_code', 'site_id',
        'photo_date', 'caption',
        'disk', 'path', 'thumb_path', 'mime', 'width', 'height',
        'bytes', 'original_bytes', 'original_name', 'uploaded_by_id',
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
