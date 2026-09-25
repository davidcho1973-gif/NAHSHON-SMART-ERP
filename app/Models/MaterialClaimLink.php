<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 송장 품목 → 계약 줄 연결의 기억(현장별). factor = 송장 단위 1 이 계약 단위로 얼마. */
class MaterialClaimLink extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['factor' => 'decimal:6'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ContractBoqLine::class, 'contract_boq_line_id');
    }

    /** «1/2" EMT 10FT» → «12emt10ft» — 띄어쓰기·기호·대소문자가 달라도 같은 품목. */
    public static function keyOf(string $name): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name)), 0, 255);
    }
}
