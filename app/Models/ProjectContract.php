<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectContract extends Model
{
    use HasFactory;

    public const DIRECTION_OPTIONS = [
        'receivable' => '원청 수주 계약 (Receivable)',
        'payable' => '협력사 발주 계약 (Payable)',
        'mutual' => '상호 계약 (Mutual)',
    ];

    public const COUNTERPARTY_ROLE_OPTIONS = [
        'owner' => 'Owner / 발주처',
        'end_client' => 'End Client / 최종 발주처',
        'general_contractor' => 'General Contractor / 원청사',
        'epc' => 'EPC / 종합건설사',
        'upper_tier_contractor' => 'Upper-tier Contractor / 상위 원청사',
        'subcontractor' => 'Subcontractor / 협력사',
        'vendor' => 'Vendor / 공급사',
    ];

    public const TYPE_OPTIONS = [
        'prime_contract' => 'Prime Contract / 원청 계약',
        'master_service_agreement' => 'Master Service Agreement (MSA)',
        'subcontract' => 'Subcontract / 하도급 계약',
        'purchase_order' => 'Purchase Order / 구매·발주',
        'work_order' => 'Work Order / 작업지시',
        'amendment' => 'Amendment / 변경계약',
        'other' => 'Other / 기타',
    ];

    public const STATUS_OPTIONS = [
        'draft' => 'Draft / 작성 중',
        'under_review' => 'Under Review / 검토 중',
        'active' => 'Active / 유효',
        'suspended' => 'Suspended / 중지',
        'completed' => 'Completed / 완료',
        'terminated' => 'Terminated / 해지',
        'expired' => 'Expired / 만료',
    ];

    public const RISK_OPTIONS = [
        'low' => 'Low / 낮음',
        'medium' => 'Medium / 보통',
        'high' => 'High / 높음',
        'critical' => 'Critical / 긴급',
    ];

    protected $fillable = [
        'company_id',
        'counterparty_company_id',
        'counterparty_vendor_id',
        'site_id',
        'project_id',
        'manager_employee_id',
        'internal_reference',
        'contract_number',
        'title',
        'direction',
        'counterparty_role',
        'contract_type',
        'status',
        'risk_level',
        'executed_on',
        'effective_on',
        'starts_on',
        'ends_on',
        'notice_to_proceed_on',
        'renewal_notice_days',
        'next_action_on',
        'next_action_notes',
        'original_amount',
        'approved_change_amount',
        'current_amount',
        'currency',
        'retainage_percent',
        'payment_terms',
        'insurance_required',
        'bond_required',
        'prevailing_wage_required',
        'certified_payroll_required',
        'lien_notice_required',
        'counterparty_contact_name',
        'counterparty_contact_email',
        'counterparty_contact_phone',
        'scope_of_work',
        'notes',
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProjectContract $contract): void {
            if (blank($contract->internal_reference)) {
                $contract->internal_reference = static::nextInternalReference();
            }
        });

        static::saving(function (ProjectContract $contract): void {
            if ($contract->current_amount === null && $contract->original_amount !== null) {
                $contract->current_amount = (float) $contract->original_amount + (float) ($contract->approved_change_amount ?? 0);
            }

            $contract->currency = strtoupper($contract->currency ?: 'USD');
        });

        static::deleting(function (ProjectContract $contract): void {
            $contract->documents()->get()->each->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'executed_on' => 'date',
            'effective_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'notice_to_proceed_on' => 'date',
            'next_action_on' => 'date',
            'original_amount' => 'decimal:2',
            'approved_change_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'retainage_percent' => 'decimal:2',
            'insurance_required' => 'boolean',
            'bond_required' => 'boolean',
            'prevailing_wage_required' => 'boolean',
            'certified_payroll_required' => 'boolean',
            'lien_notice_required' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'counterparty_company_id');
    }

    /**
     * 발주(payable) 계약의 상대를 거래처 마스터로 잇는다. 같은 협력사가 계약에서는
     * company, 발주에서는 문자열, 거래처 화면에서는 vendor 행으로 세 벌이던 것을
     * vendors 정본 하나로 모으는 연결이다.
     */
    public function counterpartyVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'counterparty_vendor_id');
    }

    /** 이 계약에 걸린 발주들 — 계약 대비 발주 누계의 근거. */
    public function procurementItems(): HasMany
    {
        return $this->hasMany(ProcurementItem::class, 'contract_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectContractDocument::class);
    }

    public function currentDocuments(): HasMany
    {
        return $this->documents()->where('is_current', true);
    }

    public function expiringDocuments(): HasMany
    {
        return $this->documents()
            ->where('is_current', true)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [today(), today()->addDays(30)]);
    }

    private static function nextInternalReference(): string
    {
        $prefix = 'CTR-'.now()->format('Y');
        $sequence = static::query()->where('internal_reference', 'like', $prefix.'-%')->count() + 1;

        do {
            $reference = sprintf('%s-%05d', $prefix, $sequence);
            $sequence++;
        } while (static::query()->where('internal_reference', $reference)->exists());

        return $reference;
    }
}
