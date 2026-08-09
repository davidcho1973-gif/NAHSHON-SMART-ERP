<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W-9 제출본 — 1099 지급의 전제조건. TIN 원문은 암호화되어 저장되고
 * 화면·목록에는 tin_last4 만 쓴다. 직원당 한 장(재제출은 덮어씀).
 */
class W9Form extends Model
{
    protected $table = 'w9_forms';

    /** IRS W-9 Line 3 연방 세무 분류. */
    public const TAX_CLASSIFICATIONS = [
        'individual' => 'Individual / Sole proprietor',
        'c_corp' => 'C Corporation',
        's_corp' => 'S Corporation',
        'partnership' => 'Partnership',
        'trust_estate' => 'Trust / Estate',
        'llc' => 'LLC',
        'other' => 'Other',
    ];

    protected $fillable = [
        'employee_id',
        'legal_name',
        'business_name',
        'tax_classification',
        'llc_tax_class',
        'address',
        'city_state_zip',
        'tin_type',
        'tin',
        'tin_last4',
        'signature_name',
        'certified_at',
        'signed_ip',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tin' => 'encrypted',
            'certified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** 마스킹된 TIN — 목록·확인 화면용. 예) ***-**-1234 */
    public function maskedTin(): string
    {
        return ($this->tin_type === 'ein' ? '**-***' : '***-**-').$this->tin_last4;
    }
}
