<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 품목 마스터 — 자재/공구/장비/안전/가설 품목의 기준 정보.
 * 자산가치보다 취득목적·사용추적 중심이라 분류(ItemCategory)와
 * 단위/표준단가만 기준으로 잡고 나머지는 payload 로 확장한다.
 */
class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'item_category_id',
        'code',
        'name',
        'unit',
        'standard_cost',
        'description',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }
}
