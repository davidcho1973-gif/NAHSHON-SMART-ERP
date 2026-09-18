<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 점검 질문지 — 장비 <b>종류</b>에 붙는다(한 대가 아니라).
 *
 * 좁은 것이 이긴다: 그 장비 한 대 전용 > equipment_type > trade > category_group.
 * 그래야 「굴착기 전부」 위에 「그 한 대만 추가로」 를 덧댈 수 있고, 덧댄 것을
 * 지우면 다시 공통으로 돌아온다.
 */
class EquipmentChecklistTemplate extends Model
{
    use HasFactory;

    /** 좁은 것부터 — 이 순서가 «어느 질문지를 쓰나» 의 규칙이다. */
    public const SCOPE_PRIORITY = ['equipment', 'equipment_type', 'trade', 'category_group'];

    public const STAGE_PRE = 'pre_use';

    public const STAGE_POST = 'post_use';

    protected $fillable = [
        'company_id',
        'site_id',
        'scope_type',
        'scope_value',
        'name',
        'stage',
        'status',
        'is_default',
        'sort_order',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EquipmentChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EquipmentChecklistLog::class);
    }
}
