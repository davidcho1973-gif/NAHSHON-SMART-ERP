<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RFI 가 건드린 계약 줄 하나와 그 수량 변화(추가 +, 감액 −). */
class ContractChangeLine extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['qty_delta' => 'decimal:4', 'qty_before' => 'decimal:4', 'amount' => 'decimal:2'];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ContractChange::class, 'contract_change_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ContractBoqLine::class, 'contract_boq_line_id');
    }
}
