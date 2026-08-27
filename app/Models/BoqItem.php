<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 물량/BOQ 한 행 — 공정별 견적 라인아이템.
 *
 * qty_basis 가 '미확정'(LS)인 행은 도면 실측 후 수량을 채우는 대상이고,
 * flagged 는 단가 산정 편차가 커서 검토가 필요한 행이다.
 * amount 는 저장 시 qty × unit_price 로 자동 재계산된다.
 */
class BoqItem extends Model
{
    public const QTY_BASIS_OPTIONS = [
        '문서확정' => '문서확정',
        '도면판독' => '도면판독',
        '개산추정' => '개산추정',
        '미확정' => '미확정',
    ];

    protected $fillable = [
        'company_id', 'site_id', 'project_id',
        'seq', 'discipline_code', 'discipline', 'name_kr', 'name_en', 'spec',
        'unit', 'qty', 'qty_basis', 'unit_price', 'amount', 'source', 'note', 'flagged',
        'wbs_activity_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'flagged' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BoqItem $item): void {
            $item->amount = round((float) $item->qty * (float) $item->unit_price, 2);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
