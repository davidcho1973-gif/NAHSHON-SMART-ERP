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

    /**
     * 직원 정보로 미리 채운 W-9 초안.
     *
     * 자동으로 채울 수 있는 것과 없는 것이 갈린다. 이름·주소·세무분류는 우리가 알고 있으니
     * 채운다. <b>TIN 과 서명은 채우지 않는다</b> — TIN 은 우리가 가진 적이 없고, 서명은
     * "위증 시 처벌을 감수한다" 는 본인 진술이다. 대신 써 주면 그건 서류 위조다.
     *
     * 그래서 이 초안의 목적은 작성자가 <b>두 칸만</b> 채우면 되게 만드는 것이다.
     *
     * @return array<string, string>
     */
    public static function prefillFor(Employee $employee): array
    {
        $payload = is_array($employee->payload) ? $employee->payload : [];

        $cityStateZip = trim(implode(' ', array_filter([
            trim((string) ($payload['city'] ?? '')),
            trim((string) ($payload['state'] ?? '')),
            trim((string) ($payload['zip'] ?? $payload['postal_code'] ?? '')),
        ])));

        return [
            'legal_name' => trim((string) $employee->name),
            'business_name' => '',
            // 1099 를 받는 현장 작업자는 거의 전부 개인이다. 회사면 본인이 바꾸면 된다.
            'tax_classification' => 'individual',
            'address' => trim((string) ($payload['address'] ?? '')),
            'city_state_zip' => $cityStateZip,
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
