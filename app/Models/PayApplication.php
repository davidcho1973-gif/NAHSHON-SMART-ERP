<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 기성 청구 회차 (Pay Application) — 수주 계약의 청구 원장.
 *
 * 파생 금액(D·G·held·line6·line7·due)의 산식 정본은 BillingCalculator 다.
 * 모델 훅은 채번만 한다 — saving 훅에 산식을 분산하면 정본이 둘이 된다.
 */
class PayApplication extends Model
{
    use HasFactory;

    public const STATUS_OPTIONS = [
        'draft' => 'Draft / 작성 중',
        'submitted' => 'Submitted / 제출됨',
        'approved' => 'Approved / 승인됨',
        'paid' => 'Paid / 수금 완료',
    ];

    public const TYPE_OPTIONS = [
        'progress' => 'Progress / 기성 청구',
        'retainage_release' => 'Retainage Release / 유보 해제',
        'final' => 'Final / 최종 청구',
    ];

    protected $fillable = [
        'project_contract_id',
        'company_id',
        'site_id',
        'project_id',
        'internal_reference',
        'application_no',
        'type',
        'status',
        'period_start',
        'period_end',
        'submitted_on',
        'approved_on',
        'due_on',
        'this_period_amount',
        'stored_materials_amount',
        'previous_billed_amount',
        'cumulative_amount',
        'retainage_percent',
        'retainage_released',
        'retainage_held',
        'earned_less_retainage',
        'previous_certificates',
        'amount_due',
        'approved_amount',
        'conditional_waiver_on',
        'unconditional_waiver_on',
        'paid_at',
        'paid_by_user_id',
        'intelligent_document_id',
        'source_ref',
        'notes',
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (PayApplication $application): void {
            if (blank($application->internal_reference)) {
                $application->internal_reference = static::nextInternalReference();
            }

            // 계약 내 회차 번호 자동 채번 — 연속성 사슬(D = 전회 D+E)의 정렬 축
            if (blank($application->application_no)) {
                $application->application_no = (int) static::query()
                    ->where('project_contract_id', $application->project_contract_id)
                    ->max('application_no') + 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'submitted_on' => 'date',
            'approved_on' => 'date',
            'due_on' => 'date',
            'conditional_waiver_on' => 'date',
            'unconditional_waiver_on' => 'date',
            'this_period_amount' => 'decimal:2',
            'stored_materials_amount' => 'decimal:2',
            'previous_billed_amount' => 'decimal:2',
            'cumulative_amount' => 'decimal:2',
            'retainage_percent' => 'decimal:2',
            'retainage_released' => 'decimal:2',
            'retainage_held' => 'decimal:2',
            'earned_less_retainage' => 'decimal:2',
            'previous_certificates' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    /** 이 회차에 배정된 수금들 — 미배정(계약 직속) 입금은 여기에 나타나지 않는다. */
    public function receipts(): HasMany
    {
        return $this->hasMany(BillingReceipt::class, 'pay_application_id');
    }

    public function intelligentDocument(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }

    /**
     * PA-2026-00001 자동 채번 — ProjectContract::nextInternalReference() 패턴 복제.
     * count+1 로 시작점을 잡고 do-while 로 충돌(삭제·동시 생성 잔재)을 건너뛴다.
     */
    private static function nextInternalReference(): string
    {
        $prefix = 'PA-'.now()->format('Y');
        $sequence = static::query()->where('internal_reference', 'like', $prefix.'-%')->count() + 1;

        do {
            $reference = sprintf('%s-%05d', $prefix, $sequence);
            $sequence++;
        } while (static::query()->where('internal_reference', $reference)->exists());

        return $reference;
    }
}
