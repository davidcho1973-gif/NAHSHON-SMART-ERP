<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 수금 (Billing Receipt) — 입금은 사실 기록이라 상태가 없다.
 *
 * 계약 직속(project_contract_id 필수)이고 회차(pay_application_id)는 nullable —
 * 입금이 먼저 오고 매칭은 나중인 현실을 수용한다(null = 매칭 대기).
 * 금액·날짜 수정은 없다(틀리면 삭제 후 재입력) — 장부성 단순화.
 */
class BillingReceipt extends Model
{
    use HasFactory;

    public const METHOD_OPTIONS = [
        'check' => 'Check / 수표',
        'ach' => 'ACH / 전자이체',
        'wire' => 'Wire / 전신 송금',
        'other' => 'Other / 기타',
    ];

    public const DEDUCTION_REASON_OPTIONS = [
        'backcharge' => 'Backcharge / 상계',
        'discount' => 'Discount / 할인',
        'adjustment' => 'Adjustment / 조정',
        'other' => 'Other / 기타',
    ];

    protected $fillable = [
        'project_contract_id',
        'pay_application_id',
        'company_id',
        'site_id',
        'received_on',
        'amount',
        'method',
        'reference',
        'deduction_amount',
        'deduction_reason',
        'deduction_accepted',
        'recorded_by_user_id',
        'intelligent_document_id',
        'source_ref',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            // 3상태: null=미판단 / true=인정 / false=불인정(분쟁) — true 만 회차 잔액에서 차감
            'deduction_accepted' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    /** 배정된 기성 회차 — null 이면 매칭 대기 (계약 직속 입금). */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PayApplication::class, 'pay_application_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
