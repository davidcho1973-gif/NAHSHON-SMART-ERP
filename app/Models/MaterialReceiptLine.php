<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 납품서의 한 줄 — 무엇이 몇 개 왔는가. */
class MaterialReceiptLine extends Model
{
    protected $fillable = [
        'material_receipt_id', 'item_id', 'name', 'quantity', 'unit', 'unit_price', 'note', 'seq',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MaterialReceipt::class, 'material_receipt_id');
    }

    /** 품목 마스터 — 붙어 있으면 집계가 한 줄로 모인다. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** 이 줄의 금액. 단가가 없으면 없는 것이다 — 0 으로 치면 «공짜로 받았다» 가 된다. */
    public function amount(): ?float
    {
        return $this->unit_price === null ? null : round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
